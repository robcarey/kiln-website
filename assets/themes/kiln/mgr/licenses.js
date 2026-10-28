/*
 * Extras → Kiln Licences. Three tabs (Licences, Teams, Audit log) and the
 * windows behind them. Every call goes through MODX's connector to a
 * KilnWeb\Processors\* class, which talks to license.kilndev.com.
 * Windows only close from their own buttons (never from the backdrop).
 */
var KilnLic = function (config) {
    KilnLic.superclass.constructor.call(this, config || {});
};
Ext.extend(KilnLic, Ext.Component, { page: {}, panel: {}, grid: {}, config: {} });
KilnLic = new KilnLic();

KilnLic.P = 'KilnWeb\\Processors\\';
KilnLic.esc = function (v) { return Ext.util.Format.htmlEncode(v === null || v === undefined ? '' : String(v)); };
KilnLic.planLabel = function (p) { return (KilnLic.config.plans && KilnLic.config.plans[p]) || p; };
KilnLic.humanId = function (uuid) {
    if (!uuid) { return ''; }
    var h = String(uuid).replace(/-/g, '').toUpperCase();
    return 'KILN-' + h.substr(0, 4) + '-' + h.substr(4, 4) + '-' + h.substr(8, 4);
};
KilnLic.day = function (iso) { return iso ? String(iso).substr(0, 10) : ''; };
KilnLic.when = function (iso) { return iso ? String(iso).replace('T', ' ').substr(0, 16) : ''; };
KilnLic.pill = function (state, label) {
    // also a grid renderer, where the second argument is Ext's cell metadata
    return '<span class="kilnlic-pill kilnlic-pill--' + KilnLic.esc(state) + '">' + KilnLic.esc(typeof label === 'string' ? label : state) + '</span>';
};

KilnLic.licenceFields = ['id', 'licenseId', 'email', 'plan', 'planLabel', 'tier', 'iat', 'exp', 'paidThrough', 'teamId', 'orderRef',
    'accountRef', 'account', 'status', 'state', 'expired', 'revokedAt', 'revokeReason', 'serial', 'notes', 'createdBy', 'createdAt'];

/* ---------------------------------------------------------------- plumbing */

KilnLic.call = function (action, params, fn) {
    MODx.Ajax.request({
        url: MODx.config.connector_url,
        params: Ext.apply({ action: KilnLic.P + action }, params || {}),
        listeners: {
            success: { fn: function (r) { if (fn) { fn(r); } else { KilnLic.done(r); } } },
            failure: { fn: Ext.emptyFn } // MODx shows the server's message
        }
    });
};

KilnLic.done = function (r) {
    if (r && r.message) {
        MODx.msg.status({ title: 'Done', message: KilnLic.esc(r.message), delay: 6 });
    }
    KilnLic.refresh();
};

KilnLic.refresh = function () {
    Ext.each(['kilnlic-grid-licenses', 'kilnlic-grid-teams', 'kilnlic-grid-orders', 'kilnlic-grid-audit', 'kilnlic-grid-members'], function (id) {
        var g = Ext.getCmp(id);
        if (g && g.rendered) { g.refresh(); }
    });
};

KilnLic.copy = function (text) {
    var ok = function () { MODx.msg.status({ title: 'Copied', message: 'The licence key is on your clipboard.', delay: 2 }); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(ok, function () { Ext.Msg.alert('Copy', 'Your browser would not copy it. Select the key and copy it by hand.'); });
    } else {
        var t = document.createElement('textarea');
        t.value = text; document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); ok(); } catch (e) { /* select-and-copy is the fallback */ }
        document.body.removeChild(t);
    }
};

/** A form window posting to one processor. Static: only its buttons close it. */
KilnLic.form = function (title, action, fields, opts) {
    opts = opts || {};
    var w = new MODx.Window({
        title: title,
        url: MODx.config.connector_url,
        baseParams: Ext.apply({ action: KilnLic.P + action }, opts.params || {}),
        fields: fields,
        width: opts.width || 520,
        closeAction: 'close',
        modal: true,
        autoHeight: true,
        saveBtnText: opts.save || 'Save',
        listeners: {
            success: { fn: function (o) { KilnLic.done(o.a.result); if (opts.after) { opts.after(o.a.result); } } }
        }
    });
    w.show();
    if (opts.values) { w.fp.getForm().setValues(opts.values); }
    if (opts.shown) { opts.shown(w); }
    return w;
};

/** Show or hide a field together with its label. */
KilnLic.toggle = function (form, name, on) {
    var f = form.findField(name);
    if (!f) { return; }
    var item = f.getEl() ? f.getEl().up('.x-form-item') : null;
    if (item) { item.setDisplayed(on); }
    f.setDisabled(!on);
};

KilnLic.localCombo = function (cfg, data) {
    return Ext.apply({
        xtype: 'combo',
        store: new Ext.data.ArrayStore({ fields: ['v', 'd'], data: data }),
        mode: 'local',
        valueField: 'v',
        displayField: 'd',
        hiddenName: cfg.name,
        triggerAction: 'all',
        editable: false,
        forceSelection: true,
        anchor: '100%'
    }, cfg);
};

KilnLic.sendBox = function (label, checked) {
    return { xtype: 'xcheckbox', name: 'send', boxLabel: label || 'Email the key to them', hideLabel: true, inputValue: 1, checked: checked !== false };
};

/* ---------------------------------------------------------------- windows: licences */

KilnLic.mint = function (values) {
    var apply = function (w) {
        var form = w.fp.getForm(), plan = form.findField('plan').getValue();
        KilnLic.toggle(form, 'paidThrough', plan === 'annual');
        KilnLic.toggle(form, 'exp', plan === 'comp');
        w.doLayout(); w.center();
    };
    KilnLic.form('Mint a licence', 'Licenses\\Create', [
        { xtype: 'textfield', name: 'email', fieldLabel: 'Email', vtype: 'email', allowBlank: false, anchor: '100%',
          description: 'Doesn’t need an account. If this email has one the licence goes straight onto it; otherwise it’s waiting when they sign up and verify this address.' },
        KilnLic.localCombo({ name: 'plan', fieldLabel: 'Plan', value: 'comp', listeners: { select: function (c) { apply(c.findParentByType('modx-window')); } } },
            [['comp', 'Complimentary'], ['lifetime', 'Pro · Lifetime'], ['annual', 'Pro · Annual']]),
        { xtype: 'datefield', name: 'paidThrough', fieldLabel: 'Paid through (keeps working 14 days past this)', format: 'Y-m-d', value: KilnLic.config.yearOn, anchor: '50%' },
        { xtype: 'datefield', name: 'exp', fieldLabel: 'Expires (leave empty for never)', format: 'Y-m-d', anchor: '50%' },
        { xtype: 'textfield', name: 'orderRef', fieldLabel: 'Order or reference (optional)', anchor: '100%' },
        { xtype: 'textarea', name: 'notes', fieldLabel: 'Notes (only you see these)', anchor: '100%', height: 60 },
        KilnLic.sendBox()
    ], { save: 'Mint', values: values, shown: apply });
};

KilnLic.trial = function (values) {
    KilnLic.form('Start a 7-day trial', 'Licenses\\Create', [
        { xtype: 'textfield', name: 'email', fieldLabel: 'Email', vtype: 'email', allowBlank: false, anchor: '100%' },
        { xtype: 'xcheckbox', name: 'force', boxLabel: 'Allow a second trial for this email', hideLabel: true, inputValue: 1 },
        { xtype: 'textarea', name: 'notes', fieldLabel: 'Notes (only you see these)', anchor: '100%', height: 60 },
        KilnLic.sendBox()
    ], { save: 'Start trial', params: { plan: 'trial' }, values: values });
};

KilnLic.op = function (l, op) {
    var id = l.id, label = l.licenseId;
    if (op === 'unrevoke') {
        Ext.Msg.confirm('Unrevoke ' + label, 'Make ' + KilnLic.esc(label) + ' active again? It leaves revoked.json on the next publish.', function (b) {
            if (b === 'yes') { KilnLic.call('Licenses\\Update', { id: id, op: 'unrevoke' }); }
        });
        return;
    }
    var forms = {
        revoke: ['Revoke ' + label, 'Revoke', [
            { html: '<p class="kilnlic-warn">Kiln on that person’s machines drops to Free after the 14-day grace period. You can unrevoke it later.</p>', border: false },
            { xtype: 'textfield', name: 'reason', fieldLabel: 'Reason (only you see this)', anchor: '100%' }
        ]],
        extend: ['Change expiry of ' + label, 'Save', [
            { xtype: 'datefield', name: 'exp', fieldLabel: 'Valid until', format: 'Y-m-d', value: l.exp || KilnLic.config.yearOn, anchor: '50%' },
            { xtype: 'xcheckbox', name: 'never', boxLabel: 'Never expires', hideLabel: true, inputValue: 1, checked: !l.exp },
            { html: '<p class="kilnlic-hint">The key is re-signed. The app picks up the new one from 0.1.7; until then, email it.</p>', border: false },
            KilnLic.sendBox('Email the new key to ' + l.email, false)
        ]],
        renew: ['Renew ' + label, 'Renew', [
            { xtype: 'datefield', name: 'paidThrough', fieldLabel: 'Paid through', format: 'Y-m-d', allowBlank: false, anchor: '50%',
              value: l.paidThrough ? Date.parseDate(l.paidThrough, 'Y-m-d').add(Date.YEAR, 1) : KilnLic.config.yearOn },
            KilnLic.sendBox('Email the renewed key to ' + l.email, false)
        ]],
        transfer: ['Transfer ' + label, 'Transfer', [
            { html: '<p class="kilnlic-hint">Moves this licence to another email (and its account, if it has one). The key is re-signed for the new email.</p>', border: false },
            { xtype: 'textfield', name: 'email', fieldLabel: 'New email', vtype: 'email', allowBlank: false, anchor: '100%' },
            KilnLic.sendBox('Email the new key to them')
        ]]
    };
    var f = forms[op];
    KilnLic.form(f[0], 'Licenses\\Update', f[2], { save: f[1], params: { id: id, op: op } });
};

KilnLic.resend = function (l) {
    KilnLic.form('Email ' + l.licenseId, 'Licenses\\Resend', [
        { xtype: 'textfield', name: 'to', fieldLabel: 'Send to', vtype: 'email', value: l.email, allowBlank: false, anchor: '100%' }
    ], { save: 'Send', params: { id: l.id } });
};

KilnLic.details = function (id) {
    KilnLic.call('Licenses\\Get', { id: id }, function (r) {
        var l = r.object, e = KilnLic.esc;
        var row = function (k, v) { return v === '' || v === null || v === undefined ? '' : '<dt>' + k + '</dt><dd>' + v + '</dd>'; };
        var audit = Ext.isArray(l.audit) && l.audit.length ? l.audit.map(function (a) {
            return '<tr><td>' + e(KilnLic.when(a.at)) + '</td><td>' + e(a.action) + '</td><td>' + e(a.actor) + '</td><td class="kilnlic-mono">' + e(a.detail ? JSON.stringify(a.detail) : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="4">No history.</td></tr>';
        var html = '<div class="kilnlic-details">'
            + '<dl class="kilnlic-dl">'
            + row('Licence', '<strong>' + e(l.licenseId) + '</strong> ' + KilnLic.pill(l.state))
            + row('Email', e(l.email))
            + row('Plan', e(l.planLabel))
            + row('Issued', e(l.iat))
            + row('Expires', l.exp ? e(l.exp) : 'Never')
            + row('Paid through', e(l.paidThrough))
            + row('Account', l.account ? e(l.account) + ' (#' + e(l.accountRef) + ')' : '<em>not claimed yet</em>')
            + row('Team', e(l.teamId))
            + row('Order', e(l.orderRef))
            + row('Revoked', l.revokedAt ? e(KilnLic.when(l.revokedAt)) + (l.revokeReason ? ' (' + e(l.revokeReason) + ')' : '') : '')
            + row('Created', e(KilnLic.when(l.createdAt)) + ' by ' + e(l.createdBy))
            + row('Notes', e(l.notes))
            + row('UUID', '<span class="kilnlic-mono">' + e(l.id) + '</span>')
            + '</dl>'
            + '<h4>Licence key</h4><textarea class="kilnlic-serial" readonly rows="4">' + e(l.serial) + '</textarea>'
            + '<h4>History</h4><table class="kilnlic-table"><thead><tr><th>When</th><th>What</th><th>Who</th><th>Detail</th></tr></thead><tbody>' + audit + '</tbody></table>'
            + '</div>';
        var w = new Ext.Window({
            title: l.licenseId + ' · ' + l.email,
            width: 680, modal: true, closable: true, resizable: true, autoScroll: true,
            cls: 'modx-window kilnlic-window', bodyStyle: 'padding:16px', html: html,
            buttons: [
                { text: 'Copy key', handler: function () { KilnLic.copy(l.serial); } },
                { text: 'Email key…', disabled: l.status !== 'active', handler: function () { KilnLic.resend(l); } },
                { text: 'Customer…', handler: function () { w.close(); KilnLic.customer(l.email); } },
                { text: 'Close', cls: 'primary-button', handler: function () { w.close(); } }
            ]
        });
        w.show();
    });
};

KilnLic.customer = function (email) {
    KilnLic.call('Customers\\Get', { email: email }, function (r) {
        var c = r.object, e = KilnLic.esc, a = c.account;
        var acct;
        if (a) {
            var ids = Ext.isObject(a.identities) ? Object.keys(a.identities).join(', ') : '';
            acct = '<dl class="kilnlic-dl">'
                + '<dt>Account</dt><dd><a href="' + e(a.editUrl) + '">' + e(a.username) + '</a> (#' + a.id + ')' + (a.staff ? ' · <strong>staff</strong>' : '') + '</dd>'
                + (a.name ? '<dt>Name</dt><dd>' + e(a.name) + '</dd>' : '')
                + '<dt>Status</dt><dd>' + (a.blocked ? KilnLic.pill('blocked') : (a.active ? KilnLic.pill('active') : KilnLic.pill('unverified'))) + '</dd>'
                + '<dt>Signed up</dt><dd>' + e(a.createdon || '—') + '</dd>'
                + '<dt>Last sign-in</dt><dd>' + e(a.lastLogin || 'never') + ' (' + a.logins + ' in all)</dd>'
                + '<dt>Linked sign-ins</dt><dd>' + (ids ? e(ids) : 'none') + '</dd>'
                + '</dl>';
        } else {
            acct = '<p class="kilnlic-hint">No account for this email yet. Anything minted for it waits here and joins their account when they sign up and verify this address.</p>';
        }
        var lic = c.licenses.length ? c.licenses.map(function (l) {
            return '<tr><td><a href="#" data-kilnlic-licence="' + e(l.id) + '">' + e(l.licenseId) + '</a></td><td>' + e(l.planLabel) + '</td><td>' + KilnLic.pill(l.state)
                + '</td><td>' + (l.exp ? e(l.exp) : 'Never') + '</td><td>' + (l.email !== c.email ? e(l.email) : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="5">No licences.</td></tr>';
        var teams = c.teams.length ? c.teams.map(function (t) {
            return '<tr><td>' + e(t.id) + '</td><td>' + e(t.name || '') + '</td><td>' + (t.plan === 'enterprise' ? 'Enterprise' : 'Team') + '</td><td>' + t.seatsUsed + ' / ' + t.seats + '</td><td>' + e(t.paidThrough || 'no end') + '</td></tr>';
        }).join('') : '';
        var orders = (c.orders || []).map(function (o) {
            return '<tr><td><a href="#" class="kilnlic-mono" data-kilnlic-order="' + e(o.ref) + '">' + e(o.ref) + '</a></td><td>' + e(o.created) + '</td><td>' + e(o.summary) + '</td><td>' + e(o.total) + '</td><td>' + KilnLic.orderPill(o.status, o.statusLabel) + '</td></tr>';
        }).join('');
        var html = '<div class="kilnlic-details">' + acct
            + '<h4>Licences</h4><table class="kilnlic-table"><thead><tr><th>Licence</th><th>Plan</th><th>Status</th><th>Expires</th><th>Issued to</th></tr></thead><tbody>' + lic + '</tbody></table>'
            + (teams ? '<h4>Teams they own</h4><table class="kilnlic-table"><thead><tr><th>Team</th><th>Name</th><th>Plan</th><th>Seats</th><th>Paid through</th></tr></thead><tbody>' + teams + '</tbody></table>' : '')
            + (orders ? '<h4>Orders</h4><table class="kilnlic-table"><thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr></thead><tbody>' + orders + '</tbody></table>' : '')
            + '</div>';
        var w = new Ext.Window({
            title: 'Customer · ' + c.email,
            width: 680, modal: true, closable: true, resizable: true, autoScroll: true,
            cls: 'modx-window kilnlic-window', bodyStyle: 'padding:16px', html: html,
            listeners: {
                afterrender: function (win) {
                    win.body.on('click', function (ev, t) {
                        ev.preventDefault();
                        win.close();
                        KilnLic.details(t.getAttribute('data-kilnlic-licence'));
                    }, null, { delegate: 'a[data-kilnlic-licence]' });
                    win.body.on('click', function (ev, t) {
                        ev.preventDefault();
                        win.close();
                        KilnLic.order(t.getAttribute('data-kilnlic-order'));
                    }, null, { delegate: 'a[data-kilnlic-order]' });
                }
            },
            buttons: [
                { text: 'Mint a licence…', handler: function () { w.close(); KilnLic.mint({ email: c.email }); } },
                { text: '7-day trial…', handler: function () { w.close(); KilnLic.trial({ email: c.email }); } },
                { text: 'Close', cls: 'primary-button', handler: function () { w.close(); } }
            ]
        });
        w.show();
    });
};

KilnLic.lookup = function () {
    Ext.Msg.prompt('Look up a customer', 'Email address:', function (b, v) {
        if (b === 'ok' && Ext.util.Format.trim(v)) { KilnLic.customer(Ext.util.Format.trim(v)); }
    });
};

KilnLic.showAudit = function (params) {
    var tabs = Ext.getCmp('kilnlic-tabs');
    tabs.setActiveTab('kilnlic-tab-audit');
    var g = Ext.getCmp('kilnlic-grid-audit');
    g.setFilter(params);
};

/* ---------------------------------------------------------------- windows: teams */

KilnLic.newTeam = function (plan) {
    var ent = plan === 'enterprise';
    KilnLic.form(ent ? 'New Enterprise licence' : 'New Team', 'Teams\\Create', [
        { xtype: 'textfield', name: 'ownerEmail', fieldLabel: 'Owner’s email (they hand out the seats)', vtype: 'email', allowBlank: false, anchor: '100%' },
        { xtype: 'textfield', name: 'name', fieldLabel: 'Name (optional, e.g. the company)', anchor: '100%' },
        { xtype: 'numberfield', name: 'seats', fieldLabel: 'Seats', allowDecimals: false, minValue: ent ? 1 : 3, value: ent ? 10 : 3, allowBlank: false, anchor: '30%' },
        { xtype: 'datefield', name: 'paidThrough', fieldLabel: ent ? 'Paid through (leave empty for no end)' : 'Paid through (renews yearly)', format: 'Y-m-d',
          value: KilnLic.config.yearOn, allowBlank: ent, anchor: '50%' },
        { xtype: 'textfield', name: 'orderRef', fieldLabel: 'Order, invoice or reference (optional)', anchor: '100%' },
        { xtype: 'textarea', name: 'members', fieldLabel: 'Give seats now (optional, one email per line)', anchor: '100%', height: 70 },
        { xtype: 'textarea', name: 'notes', fieldLabel: 'Notes (only you see these)', anchor: '100%', height: 50 },
        KilnLic.sendBox('Email each new member their key')
    ], { save: 'Create', params: { plan: ent ? 'enterprise' : 'team' } });
};

KilnLic.editTeam = function (t) {
    KilnLic.form('Edit ' + t.id, 'Teams\\Update', [
        { xtype: 'textfield', name: 'name', fieldLabel: 'Name', value: t.name || '', anchor: '100%' },
        { xtype: 'numberfield', name: 'seats', fieldLabel: 'Seats (' + t.seatsUsed + ' in use)', allowDecimals: false, minValue: Math.max(1, t.seatsUsed), value: t.seats, anchor: '30%' },
        { xtype: 'datefield', name: 'paidThrough', fieldLabel: 'Paid through (a later date re-signs every seat’s key)', format: 'Y-m-d', value: t.paidThrough || '', anchor: '50%' },
        t.plan === 'enterprise' ? { xtype: 'xcheckbox', name: 'noEnd', boxLabel: 'No end date', hideLabel: true, inputValue: 1, checked: !t.paidThrough } : { xtype: 'hidden', name: 'noEnd', value: 0 },
        KilnLic.localCombo({ name: 'status', fieldLabel: 'Status', value: t.status, anchor: '50%' }, [['active', 'Active'], ['cancelled', 'Cancelled (no new seats)']])
    ], { params: { id: t.id } });
};

KilnLic.team = function (t) {
    var w = new Ext.Window({
        title: (t.name ? t.name + ' · ' : '') + t.id + ' · ' + (t.plan === 'enterprise' ? 'Enterprise' : 'Team'),
        width: 820, modal: true, closable: true, resizable: true, autoHeight: true,
        cls: 'modx-window kilnlic-window', layout: 'anchor',
        items: [{
            border: false, bodyStyle: 'padding:12px 16px 0',
            html: '<p>Owner <strong>' + KilnLic.esc(t.ownerEmail) + '</strong> · ' + t.seatsUsed + ' of ' + t.seats + ' seats in use · '
                + (t.paidThrough ? 'paid through ' + KilnLic.esc(t.paidThrough) + ' (keys run to ' + KilnLic.esc(t.memberExp) + ')' : 'no end date')
                + (t.status !== 'active' ? ' · ' + KilnLic.pill(t.status) : '') + '</p>'
        }, {
            xtype: 'kilnlic-grid-members', team: t, anchor: '100%'
        }],
        buttons: [
            { text: 'Edit team…', handler: function () { w.close(); KilnLic.editTeam(t); } },
            { text: 'Close', cls: 'primary-button', handler: function () { w.close(); } }
        ]
    });
    w.show();
};

KilnLic.addSeat = function (t) {
    KilnLic.form('Give a seat on ' + t.id, 'Teams\\Member', [
        { xtype: 'textfield', name: 'email', fieldLabel: 'Email', vtype: 'email', allowBlank: false, anchor: '100%' },
        KilnLic.sendBox()
    ], { save: 'Give seat', params: { id: t.id, op: 'add' } });
};

/* ---------------------------------------------------------------- licences grid */

KilnLic.grid.Licenses = function (config) {
    config = config || {};
    var team = config.team || null;
    Ext.applyIf(config, {
        id: 'kilnlic-grid-licenses',
        url: MODx.config.connector_url,
        baseParams: Ext.apply({ action: KilnLic.P + 'Licenses\\GetList' }, team ? { teamId: team.id } : {}),
        fields: KilnLic.licenceFields,
        paging: true,
        pageSize: 25,
        remoteSort: false,
        autoHeight: true,
        autoExpandColumn: 'kilnlic-col-email',
        emptyText: 'No licences match.',
        columns: [
            { header: 'Licence', dataIndex: 'licenseId', width: 170, renderer: function (v) { return '<span class="kilnlic-mono">' + KilnLic.esc(v) + '</span>'; } },
            { header: 'Email', dataIndex: 'email', id: 'kilnlic-col-email', width: 220, renderer: KilnLic.esc },
            { header: 'Plan', dataIndex: 'planLabel', width: 140, hidden: !!team, renderer: KilnLic.esc },
            { header: 'Status', dataIndex: 'state', width: 95, renderer: KilnLic.pill },
            { header: 'Expires', dataIndex: 'exp', width: 100, renderer: function (v) { return v ? KilnLic.esc(v) : '<span class="kilnlic-dim">never</span>'; } },
            { header: 'Account', dataIndex: 'account', width: 150, renderer: function (v) { return v ? KilnLic.esc(v) : '<span class="kilnlic-dim">not claimed</span>'; } },
            { header: 'Team', dataIndex: 'teamId', width: 95, hidden: true, renderer: KilnLic.esc },
            { header: 'Created', dataIndex: 'createdAt', width: 100, renderer: function (v) { return KilnLic.esc(KilnLic.day(v)); } },
            { header: 'By', dataIndex: 'createdBy', width: 130, hidden: true, renderer: KilnLic.esc }
        ],
        tbar: team ? [
            { text: '<i class="icon icon-plus"></i> Give a seat…', cls: 'primary-button', handler: function () { KilnLic.addSeat(team); } }
        ] : [
            { text: '<i class="icon icon-plus"></i> Mint…', cls: 'primary-button', handler: function () { KilnLic.mint({}); } },
            { text: '<i class="icon icon-clock-o"></i> Trial…', handler: function () { KilnLic.trial({}); } },
            { text: '<i class="icon icon-user"></i> Customer…', handler: KilnLic.lookup },
            '->',
            KilnLic.localCombo({ id: 'kilnlic-filter-plan', name: 'filterPlan', width: 140, anchor: null, emptyText: 'All plans',
                listeners: { select: { fn: function (c) { this.filter('plan', c.getValue()); }, scope: this } } },
                [['', 'All plans'], ['annual', 'Annual'], ['lifetime', 'Lifetime'], ['team', 'Team seat'], ['enterprise', 'Enterprise seat'], ['trial', 'Trial'], ['comp', 'Complimentary']]),
            KilnLic.localCombo({ id: 'kilnlic-filter-status', name: 'filterStatus', width: 115, anchor: null, emptyText: 'Any status',
                listeners: { select: { fn: function (c) { this.filter('status', c.getValue()); }, scope: this } } },
                [['', 'Any status'], ['active', 'Active'], ['revoked', 'Revoked']]),
            {
                xtype: 'textfield', id: 'kilnlic-search', width: 210, emptyText: 'Email, KILN id, order, team…',
                listeners: {
                    change: { fn: function (f) { this.filter('query', f.getValue()); }, scope: this },
                    render: function (f) {
                        new Ext.KeyMap(f.getEl(), { key: Ext.EventObject.ENTER, fn: function () { this.fireEvent('change', this); }, scope: f });
                    }
                }
            },
            { text: 'Clear', handler: this.clearFilters, scope: this }
        ]
    });
    KilnLic.grid.Licenses.superclass.constructor.call(this, config);
    this.team = team;
    this.on('rowdblclick', function (g, i) { KilnLic.details(this.getStore().getAt(i).data.id); }, this);
};
Ext.extend(KilnLic.grid.Licenses, MODx.grid.Grid, {
    filter: function (key, value) {
        this.getStore().baseParams[key] = value;
        this.getBottomToolbar().changePage(1);
    },
    clearFilters: function () {
        var p = this.getStore().baseParams;
        delete p.plan; delete p.status; delete p.query;
        Ext.getCmp('kilnlic-filter-plan').reset();
        Ext.getCmp('kilnlic-filter-status').reset();
        Ext.getCmp('kilnlic-search').reset();
        this.getBottomToolbar().changePage(1);
    },
    getMenu: function () {
        var l = this.menu.record, m = [];
        m.push({ text: 'Details…', handler: function () { KilnLic.details(l.id); } });
        m.push({ text: 'Copy key', handler: function () { KilnLic.copy(l.serial); } });
        if (l.status === 'active') { m.push({ text: 'Email key…', handler: function () { KilnLic.resend(l); } }); }
        m.push('-');
        if (this.team) {
            var t = this.team;
            if (l.status === 'active') {
                m.push({ text: 'Free this seat', handler: function () {
                    Ext.Msg.confirm('Free this seat', 'Revoke ' + KilnLic.esc(l.licenseId) + ' (' + KilnLic.esc(l.email) + ')? Their key stops working after the 14-day grace period.', function (b) {
                        if (b === 'yes') { KilnLic.call('Teams\\Member', { id: t.id, op: 'remove', license: l.id }); }
                    });
                } });
            }
        } else {
            if (l.plan === 'annual' && l.status === 'active') { m.push({ text: 'Renew…', handler: function () { KilnLic.op(l, 'renew'); } }); }
            if (l.status === 'active' && !l.teamId) {
                m.push({ text: 'Change expiry…', handler: function () { KilnLic.op(l, 'extend'); } });
                m.push({ text: 'Transfer…', handler: function () { KilnLic.op(l, 'transfer'); } });
            }
            m.push(l.status === 'active'
                ? { text: 'Revoke…', handler: function () { KilnLic.op(l, 'revoke'); } }
                : { text: 'Unrevoke', handler: function () { KilnLic.op(l, 'unrevoke'); } });
        }
        m.push('-');
        m.push({ text: 'Customer…', handler: function () { KilnLic.customer(l.email); } });
        if (!this.team) { m.push({ text: 'History', handler: function () { KilnLic.showAudit({ licenseId: l.id }); } }); }
        return m;
    }
});
Ext.reg('kilnlic-grid-licenses', KilnLic.grid.Licenses);

/** The members of one team, inside the team window. */
KilnLic.grid.Members = function (config) {
    config = config || {};
    Ext.applyIf(config, { id: 'kilnlic-grid-members', pageSize: 10, emptyText: 'No seats given out yet.' });
    KilnLic.grid.Members.superclass.constructor.call(this, config);
};
Ext.extend(KilnLic.grid.Members, KilnLic.grid.Licenses);
Ext.reg('kilnlic-grid-members', KilnLic.grid.Members);

/* ---------------------------------------------------------------- teams grid */

KilnLic.grid.Teams = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'kilnlic-grid-teams',
        url: MODx.config.connector_url,
        baseParams: { action: KilnLic.P + 'Teams\\GetList' },
        fields: ['id', 'name', 'plan', 'seats', 'seatsUsed', 'ownerEmail', 'ownerAccountRef', 'paidThrough', 'memberExp', 'orderRef', 'status', 'notes', 'createdBy', 'createdAt'],
        paging: false,
        remoteSort: false,
        autoHeight: true,
        autoExpandColumn: 'kilnlic-col-owner',
        emptyText: 'No teams yet.',
        columns: [
            { header: 'Team', dataIndex: 'id', width: 100, renderer: function (v) { return '<span class="kilnlic-mono">' + KilnLic.esc(v) + '</span>'; } },
            { header: 'Name', dataIndex: 'name', width: 160, renderer: KilnLic.esc },
            { header: 'Plan', dataIndex: 'plan', width: 95, renderer: function (v) { return v === 'enterprise' ? 'Enterprise' : 'Team'; } },
            { header: 'Owner', dataIndex: 'ownerEmail', id: 'kilnlic-col-owner', width: 220, renderer: KilnLic.esc },
            { header: 'Seats', dataIndex: 'seats', width: 80, renderer: function (v, m, r) { return r.data.seatsUsed + ' / ' + v; } },
            { header: 'Paid through', dataIndex: 'paidThrough', width: 120, renderer: function (v) { return v ? KilnLic.esc(v) : '<span class="kilnlic-dim">no end</span>'; } },
            { header: 'Status', dataIndex: 'status', width: 90, renderer: KilnLic.pill },
            { header: 'Order', dataIndex: 'orderRef', width: 100, renderer: KilnLic.esc },
            { header: 'Created', dataIndex: 'createdAt', width: 105, renderer: function (v) { return KilnLic.esc(KilnLic.day(v)); } }
        ],
        tbar: [
            { text: '<i class="icon icon-plus"></i> New Team…', cls: 'primary-button', handler: function () { KilnLic.newTeam('team'); } },
            { text: '<i class="icon icon-building"></i> New Enterprise licence…', handler: function () { KilnLic.newTeam('enterprise'); } },
            '->',
            {
                xtype: 'textfield', id: 'kilnlic-team-search', width: 260, emptyText: 'Team id, owner email, name, order…',
                listeners: {
                    change: { fn: function (f) { this.getStore().baseParams.query = f.getValue(); this.refresh(); }, scope: this },
                    render: function (f) {
                        new Ext.KeyMap(f.getEl(), { key: Ext.EventObject.ENTER, fn: function () { this.fireEvent('change', this); }, scope: f });
                    }
                }
            }
        ]
    });
    KilnLic.grid.Teams.superclass.constructor.call(this, config);
    this.on('rowdblclick', function (g, i) { this.open(this.getStore().getAt(i).data); }, this);
};
Ext.extend(KilnLic.grid.Teams, MODx.grid.Grid, {
    open: function (t) {
        KilnLic.call('Teams\\Get', { id: t.id }, function (r) { KilnLic.team(r.object); });
    },
    getMenu: function () {
        var t = this.menu.record, self = this;
        return [
            { text: 'Members…', handler: function () { self.open(t); } },
            { text: 'Edit…', handler: function () { KilnLic.editTeam(t); } },
            '-',
            { text: 'Owner…', handler: function () { KilnLic.customer(t.ownerEmail); } },
            { text: 'History', handler: function () { KilnLic.showAudit({ teamId: t.id }); } }
        ];
    }
});
Ext.reg('kilnlic-grid-teams', KilnLic.grid.Teams);

/* ---------------------------------------------------------------- audit grid */

KilnLic.grid.Audit = function (config) {
    config = config || {};
    var input = function (id, emptyText, width) {
        return {
            xtype: 'textfield', id: id, width: width, emptyText: emptyText,
            listeners: { render: function (f) { new Ext.KeyMap(f.getEl(), { key: Ext.EventObject.ENTER, fn: function () { Ext.getCmp('kilnlic-grid-audit').applyFilter(); } }); } }
        };
    };
    Ext.applyIf(config, {
        id: 'kilnlic-grid-audit',
        url: MODx.config.connector_url,
        baseParams: { action: KilnLic.P + 'Audit\\GetList', limit: 200 },
        fields: ['id', 'at', 'actor', 'action', 'licenseId', 'teamId', 'detail', 'ip'],
        paging: false,
        remoteSort: false,
        autoHeight: true,
        autoExpandColumn: 'kilnlic-col-detail',
        emptyText: 'Nothing logged.',
        columns: [
            { header: 'When (UTC)', dataIndex: 'at', width: 135, renderer: function (v) { return KilnLic.esc(KilnLic.when(v)); } },
            { header: 'What', dataIndex: 'action', width: 120, renderer: KilnLic.esc },
            { header: 'Who', dataIndex: 'actor', width: 170, renderer: KilnLic.esc },
            { header: 'Licence', dataIndex: 'licenseId', width: 150, renderer: function (v) { return v ? '<a href="#" class="kilnlic-mono" data-kilnlic-licence="' + KilnLic.esc(v) + '">' + KilnLic.esc(KilnLic.humanId(v)) + '</a>' : ''; } },
            { header: 'Team', dataIndex: 'teamId', width: 95, renderer: KilnLic.esc },
            { header: 'Detail', dataIndex: 'detail', id: 'kilnlic-col-detail', width: 260, renderer: function (v) { return '<span class="kilnlic-mono kilnlic-dim">' + KilnLic.esc(v) + '</span>'; } },
            { header: 'IP', dataIndex: 'ip', width: 110, renderer: KilnLic.esc }
        ],
        tbar: [
            input('kilnlic-audit-licence', 'Licence (KILN-… or uuid)', 200),
            input('kilnlic-audit-team', 'Team (T-…)', 110),
            input('kilnlic-audit-actor', 'Who (e.g. modx:admin:rob)', 180),
            input('kilnlic-audit-action', 'What (e.g. revoke)', 130),
            { text: 'Filter', handler: function () { this.applyFilter(); }, scope: this },
            { text: 'Clear', handler: function () { this.setFilter({}); }, scope: this },
            '->',
            { xtype: 'tbtext', text: 'Newest 200' }
        ]
    });
    KilnLic.grid.Audit.superclass.constructor.call(this, config);
    this.on('afterrender', function () {
        this.getView().mainBody.on('click', function (ev, t) {
            ev.preventDefault();
            KilnLic.details(t.getAttribute('data-kilnlic-licence'));
        }, null, { delegate: 'a[data-kilnlic-licence]' });
    }, this);
};
Ext.extend(KilnLic.grid.Audit, MODx.grid.Grid, {
    fields: { licenseId: 'kilnlic-audit-licence', teamId: 'kilnlic-audit-team', actor: 'kilnlic-audit-actor', auditAction: 'kilnlic-audit-action' },
    applyFilter: function () {
        var p = {};
        Ext.iterate(this.fields, function (k, id) { p[k] = Ext.util.Format.trim(Ext.getCmp(id).getValue()); });
        this.setFilter(p);
    },
    setFilter: function (p) {
        var bp = this.getStore().baseParams;
        Ext.iterate(this.fields, function (k, id) {
            bp[k] = p[k] || '';
            Ext.getCmp(id).setValue(p[k] || '');
        });
        this.refresh();
    }
});
Ext.reg('kilnlic-grid-audit', KilnLic.grid.Audit);

/* ---------------------------------------------------------------- sales switch */

KilnLic.salesHtml = function () {
    var s = KilnLic.config.sales || {}, e = KilnLic.esc;
    var note = '', name = { paddle: 'Paddle', test: 'the test provider' }[s.provider] || 'the payment provider';
    if (!s.ready) {
        note = '<span class="' + (s.mode === 'open' ? 'kilnlic-warn-inline' : 'kilnlic-dim') + '">'
            + (s.mode === 'open' ? 'Checkout can’t take payments: ' : 'Before opening: ') + e(name) + ' isn’t ready. '
            + (s.problems || []).map(function (p) { return e(p); }).join(' ') + '</span>';
    } else if (s.sandbox) {
        note = '<span class="kilnlic-dim">Paddle sandbox: real checkout, test cards, no money moves.</span>';
    } else if (s.test) {
        note = '<span class="kilnlic-dim">Test payments are on: checkout is a pretend page and no money moves.</span>';
    } else if (s.provider === 'paddle') {
        note = '<span class="kilnlic-dim">Paddle live: real payments.</span>';
    }
    var btn = function (mode, text) {
        return mode === s.mode ? '' : '<button type="button" class="x-btn kilnlic-sales__btn' + (mode === 'open' ? ' primary-button' : '') + '" data-kilnlic-sales="' + mode + '">' + text + '</button>';
    };
    return '<div class="kilnlic-sales__state"><strong>Sales</strong> ' + KilnLic.pill(s.mode, s.label) + ' ' + note + '</div>'
        + '<div class="kilnlic-sales__actions">' + btn('open', 'Open sales') + btn('paused', 'Pause sales') + btn('soon', 'Coming soon') + '</div>';
};

KilnLic.setSales = function (mode) {
    var text = {
        open: 'Open sales? Anyone can buy Kiln Pro from /buy, and the home page shows the prices.',
        paused: 'Pause sales? /buy says purchases are paused. Payments already made are still fulfilled.',
        soon: 'Go back to “coming soon”? /buy says Kiln Pro goes on sale soon and the prices are hidden.'
    }[mode];
    Ext.Msg.confirm('Sales', text, function (b) {
        if (b !== 'yes') { return; }
        KilnLic.call('Sales\\Set', { mode: mode }, function (r) {
            KilnLic.config.sales = r.object;
            Ext.getCmp('kilnlic-sales').update(KilnLic.salesHtml());
            MODx.msg.status({ title: 'Sales', message: KilnLic.esc(r.message), delay: 6 });
        });
    });
};

/* ---------------------------------------------------------------- orders */

KilnLic.orderFields = ['ref', 'created', 'email', 'userId', 'summary', 'total', 'status', 'statusLabel', 'provider', 'test', 'periodEnd', 'autoRenew'];
KilnLic.orderPill = function (status, label) {
    var cls = { fulfilled: 'active', paid: 'expired', pending: 'expired', refunded: 'revoked', cancelled: 'cancelled' }[status] || status;
    return '<span class="kilnlic-pill kilnlic-pill--' + KilnLic.esc(cls) + '">' + KilnLic.esc(label || status) + '</span>';
};

KilnLic.orderOp = function (o, op) {
    var confirm = {
        refund: 'Mark ' + o.ref + ' refunded and revoke every key it made? Their keys stop working after the 14-day grace period. This doesn’t move money: refund it at the payment provider too.',
        renew_test: 'Send a test “renewed” payment for ' + o.ref + '? Annual keys and the team move on a year.',
        refund_test: 'Send a test “refunded” event for ' + o.ref + '? Every key from this order is revoked.'
    }[op];
    var go = function () { KilnLic.call('Orders\\Update', { ref: o.ref, op: op }); };
    if (confirm) {
        Ext.Msg.confirm(o.ref, KilnLic.esc(confirm), function (b) { if (b === 'yes') { go(); } });
    } else {
        go();
    }
};

KilnLic.order = function (ref) {
    KilnLic.call('Orders\\Get', { ref: ref }, function (r) {
        var o = r.object, e = KilnLic.esc;
        var items = o.items.map(function (i) {
            var what = i.plan === 'team' ? 'Team · ' + i.seats + ' seats' : (i.plan === 'annual' ? 'Annual' : 'Lifetime') + (i.qty > 1 ? ' × ' + i.qty : '');
            var made = i.licences.map(function (l) {
                return '<a href="#" class="kilnlic-mono" data-kilnlic-licence="' + e(l.id) + '">' + e(l.licenseId) + '</a> ' + KilnLic.pill(l.state);
            }).join('<br>') + (i.teamId ? 'Team <span class="kilnlic-mono">' + e(i.teamId) + '</span>' : '');
            return '<tr><td>' + e(what) + '</td><td>' + e(i.line) + '</td><td>' + (made || '<span class="kilnlic-dim">not issued yet</span>') + '</td></tr>';
        }).join('');
        var events = o.events.length ? o.events.map(function (ev) {
            return '<tr><td>' + e(ev.at) + '</td><td>' + e(ev.type) + (ev.periodEnd ? ' → ' + e(ev.periodEnd) : '') + '</td><td>' + (ev.processed ? 'applied' : '<strong>not applied</strong> ' + e(ev.error)) + '</td><td class="kilnlic-mono">' + e(ev.id) + '</td></tr>';
        }).join('') : '<tr><td colspan="4">No payment events yet.</td></tr>';
        var row = function (k, v) { return v ? '<dt>' + k + '</dt><dd>' + v + '</dd>' : ''; };
        var html = '<div class="kilnlic-details"><dl class="kilnlic-dl">'
            + row('Order', '<strong>' + e(o.ref) + '</strong> ' + KilnLic.orderPill(o.status, o.statusLabel) + (o.test ? ' <span class="kilnlic-pill">test</span>' : ''))
            + row('Customer', e(o.email))
            + row('Total', e(o.total))
            + row('Created', e(o.created))
            + row('Paid', e(o.paidAt))
            + row('Renews', o.periodEnd ? e(o.periodEnd) + (o.autoRenew ? '' : ' (renewal cancelled)') : '')
            + row('Refunded', e(o.refundedAt))
            + row('Receipt sent', e(o.emailedAt))
            + row('Provider', e(o.provider) + (o.providerRef ? ' · <span class="kilnlic-mono">' + e(o.providerRef) + '</span>' : ''))
            + row('Subscription', o.providerSub ? '<span class="kilnlic-mono">' + e(o.providerSub) + '</span>' : '')
            + row('Customer', o.providerCustomer ? '<span class="kilnlic-mono">' + e(o.providerCustomer) + '</span>' : '')
            + '</dl><h4>Items</h4><table class="kilnlic-table"><thead><tr><th>Item</th><th>Price</th><th>Issued</th></tr></thead><tbody>' + items + '</tbody></table>'
            + '<h4>Payment events</h4><table class="kilnlic-table"><thead><tr><th>When</th><th>Event</th><th>Result</th><th>Id</th></tr></thead><tbody>' + events + '</tbody></table></div>';
        var testable = o.provider === 'test' && KilnLic.config.sales && KilnLic.config.sales.simulate && o.status === 'fulfilled';
        var w = new Ext.Window({
            title: 'Order ' + o.ref + ' · ' + o.email,
            width: 700, modal: true, closable: true, resizable: true, autoScroll: true,
            cls: 'modx-window kilnlic-window', bodyStyle: 'padding:16px', html: html,
            listeners: {
                afterrender: function (win) {
                    win.body.on('click', function (ev, t) { ev.preventDefault(); win.close(); KilnLic.details(t.getAttribute('data-kilnlic-licence')); }, null, { delegate: 'a[data-kilnlic-licence]' });
                }
            },
            buttons: [
                { text: 'Simulate renewal', hidden: !testable || !o.periodEnd, handler: function () { w.close(); KilnLic.orderOp(o, 'renew_test'); } },
                { text: 'Email receipt', disabled: o.status !== 'fulfilled', handler: function () { KilnLic.orderOp(o, 'resend'); } },
                { text: 'Customer…', handler: function () { w.close(); KilnLic.customer(o.email); } },
                { text: 'Close', cls: 'primary-button', handler: function () { w.close(); } }
            ]
        });
        w.show();
    });
};

KilnLic.grid.Orders = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'kilnlic-grid-orders',
        url: MODx.config.connector_url,
        baseParams: { action: KilnLic.P + 'Orders\\GetList' },
        fields: KilnLic.orderFields,
        paging: true,
        pageSize: 25,
        remoteSort: false,
        autoHeight: true,
        autoExpandColumn: 'kilnlic-col-order-items',
        emptyText: 'No orders yet.',
        columns: [
            { header: 'Order', dataIndex: 'ref', width: 115, renderer: function (v, m, r) { return '<span class="kilnlic-mono">' + KilnLic.esc(v) + '</span>' + (r.data.test ? ' <span class="kilnlic-dim">test</span>' : ''); } },
            { header: 'Date', dataIndex: 'created', width: 130, renderer: KilnLic.esc },
            { header: 'Customer', dataIndex: 'email', width: 210, renderer: KilnLic.esc },
            { header: 'Items', dataIndex: 'summary', id: 'kilnlic-col-order-items', width: 200, renderer: KilnLic.esc },
            { header: 'Total', dataIndex: 'total', width: 100, renderer: KilnLic.esc },
            { header: 'Status', dataIndex: 'status', width: 120, renderer: function (v, m, r) { return KilnLic.orderPill(v, r.data.statusLabel); } },
            { header: 'Renews', dataIndex: 'periodEnd', width: 100, renderer: function (v, m, r) { return v ? KilnLic.esc(v) + (r.data.autoRenew ? '' : ' <span class="kilnlic-dim">off</span>') : '<span class="kilnlic-dim">—</span>'; } }
        ],
        tbar: [
            '->',
            KilnLic.localCombo({ id: 'kilnlic-filter-order-status', name: 'filterOrderStatus', width: 150, anchor: null, emptyText: 'Any status',
                listeners: { select: { fn: function (c) { this.getStore().baseParams.status = c.getValue(); this.getBottomToolbar().changePage(1); }, scope: this } } },
                [['', 'Any status'], ['fulfilled', 'Complete'], ['pending', 'Awaiting payment'], ['paid', 'Paid (issuing)'], ['refunded', 'Refunded'], ['cancelled', 'Cancelled']]),
            {
                xtype: 'textfield', id: 'kilnlic-order-search', width: 220, emptyText: 'Order, email, provider id…',
                listeners: {
                    change: { fn: function (f) { this.getStore().baseParams.query = f.getValue(); this.getBottomToolbar().changePage(1); }, scope: this },
                    render: function (f) { new Ext.KeyMap(f.getEl(), { key: Ext.EventObject.ENTER, fn: function () { this.fireEvent('change', this); }, scope: f }); }
                }
            }
        ]
    });
    KilnLic.grid.Orders.superclass.constructor.call(this, config);
    this.on('rowdblclick', function (g, i) { KilnLic.order(this.getStore().getAt(i).data.ref); }, this);
};
Ext.extend(KilnLic.grid.Orders, MODx.grid.Grid, {
    getMenu: function () {
        var o = this.menu.record, m = [];
        var testable = o.provider === 'test' && KilnLic.config.sales && KilnLic.config.sales.simulate;
        m.push({ text: 'Details…', handler: function () { KilnLic.order(o.ref); } });
        if (o.status === 'fulfilled') { m.push({ text: 'Email receipt', handler: function () { KilnLic.orderOp(o, 'resend'); } }); }
        m.push({ text: 'Customer…', handler: function () { KilnLic.customer(o.email); } });
        if (o.status === 'fulfilled' || o.status === 'paid') {
            m.push('-');
            m.push({ text: 'Mark refunded and revoke keys…', handler: function () { KilnLic.orderOp(o, 'refund'); } });
        }
        if (testable && o.status === 'fulfilled') {
            m.push('-');
            if (o.periodEnd) { m.push({ text: 'Simulate renewal (test)', handler: function () { KilnLic.orderOp(o, 'renew_test'); } }); }
            m.push({ text: 'Simulate refund (test)', handler: function () { KilnLic.orderOp(o, 'refund_test'); } });
        }
        return m;
    }
});
Ext.reg('kilnlic-grid-orders', KilnLic.grid.Orders);

/* ---------------------------------------------------------------- page */

KilnLic.panel.Home = function (config) {
    config = config || {};
    var desc = function (html) { return { html: '<p>' + html + '</p>', bodyCssClass: 'panel-desc', border: false }; };
    var warn = KilnLic.config.configured ? [] : [{
        html: '<p><strong>The licence server is not set up.</strong> Fill in <code>kiln.license_api_url</code> and <code>kiln.license_api_secret</code> in System Settings (area Licensing).</p>',
        bodyCssClass: 'panel-alert-desc', border: false
    }];
    Ext.apply(config, {
        id: 'kilnlic-panel',
        cls: 'container',
        border: false,
        items: [{
            html: '<h2>Kiln Licences</h2>', cls: 'modx-page-header', border: false
        }].concat(warn, [{
            xtype: 'panel', id: 'kilnlic-sales', border: false, cls: 'kilnlic-sales', html: KilnLic.salesHtml(),
            listeners: {
                afterrender: function (p) {
                    p.body.on('click', function (ev, t) { KilnLic.setSales(t.getAttribute('data-kilnlic-sales')); }, null, { delegate: 'button[data-kilnlic-sales]' });
                }
            }
        }, {
            xtype: 'modx-tabs',
            id: 'kilnlic-tabs',
            deferredRender: false,
            defaults: { border: false, autoHeight: true },
            items: [{
                title: 'Licences',
                id: 'kilnlic-tab-licences',
                items: [desc('Every licence the server has issued. Double-click a row for its key and history; right-click for actions. '
                    + 'Mint for any email: if it has no account yet, the licence waits and joins their account when they sign up and verify that address.'),
                    { xtype: 'kilnlic-grid-licenses', cls: 'main-wrapper', preventRender: true }]
            }, {
                title: 'Teams',
                id: 'kilnlic-tab-teams',
                items: [desc('Team and Enterprise licences. The owner hands out seats from their account page; each member gets their own key. '
                    + 'Double-click a team to see and change its seats.'),
                    { xtype: 'kilnlic-grid-teams', cls: 'main-wrapper', preventRender: true }]
            }, {
                title: 'Orders',
                id: 'kilnlic-tab-orders',
                items: [desc('Every checkout on /buy, newest first. Double-click an order for its items, the keys it made and its payment events. '
                    + 'An order is only paid when the payment provider says so; its keys are issued and emailed then.'),
                    { xtype: 'kilnlic-grid-orders', cls: 'main-wrapper', preventRender: true }]
            }, {
                title: 'Audit log',
                id: 'kilnlic-tab-audit',
                items: [desc('Everything the licence server did, newest first: mints, claims, revokes, renewals and refreshes, and who asked for each.'),
                    { xtype: 'kilnlic-grid-audit', cls: 'main-wrapper', preventRender: true }]
            }]
        }])
    });
    KilnLic.panel.Home.superclass.constructor.call(this, config);
};
Ext.extend(KilnLic.panel.Home, MODx.Panel);
Ext.reg('kilnlic-panel', KilnLic.panel.Home);

KilnLic.page.Home = function (config) {
    config = config || {};
    Ext.applyIf(config, { components: [{ xtype: 'kilnlic-panel', renderTo: 'kilnlic-panel-div' }] });
    KilnLic.page.Home.superclass.constructor.call(this, config);
};
Ext.extend(KilnLic.page.Home, MODx.Component);
Ext.reg('kilnlic-page', KilnLic.page.Home);
