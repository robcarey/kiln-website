# Signing up with Paddle: Rob's checklist

Written 2026-10-27 for when you come back to this. The code side is done (see `PADDLE.md`);
everything here is what only you can do, because it's your account, your identity and your money.

There are **two separate Paddle accounts**: a sandbox (fake money, for testing, no approval
needed) and a live one (real money, needs Paddle's approval). Do the sandbox first; the live
one can wait until the legal pages are published.

---

## Part 1: Sandbox (about 15 minutes, do this first)

1. **Sign up** at <https://sandbox-login.paddle.com/signup>. Free, no business details needed.
   Afterwards the dashboard lives at <https://sandbox-vendors.paddle.com>.
2. **Create an API key:** Developer tools → Authentication → API keys → New API key.
   - Name it `kilndev.com`.
   - Give it read/write access to everything (or at least: customers, transactions, adjustments,
     subscriptions, customer portal sessions, products, notification settings).
   - It starts `pdl_sdbx_apikey_`. Paddle shows it **once**; copy it straight into step 5.
3. **Create a client-side token:** same page → Client-side tokens → New. It starts `test_`.
   This one is public by design (it goes in the web page), so it's not a secret.
4. **Set the default payment link:** Checkout → Checkout settings → Default payment link =
   `https://kilndev.com/buy`. Paddle refuses to create checkouts without one.
5. **Put the keys into MODX** (System Settings, filter on area **Paddle**). For the first trial
   use the local site, kilndev.test:
   - `kiln.paddle_env` = `sandbox`
   - `kiln.paddle_api_key` = the `pdl_sdbx_apikey_…` key
   - `kiln.paddle_client_token` = the `test_…` token

   Type them in yourself. Don't paste the API key into chat or email.
6. **Tell Claude "Paddle keys are in".** Claude then:
   - runs `_build/paddle-setup.php`, which creates the three Kiln products in Paddle;
   - switches `kiln.payment_provider` to `paddle`;
   - does a full test purchase with Paddle's test card (4242 4242 4242 4242, any future date,
     CVC 100) and checks the key arrives in the account and by email.

   On kilndev.test the first payment works end to end. Renewals and refunds need Paddle's
   webhooks, which can only reach the real server, so those get tried after the site is
   deployed to kilndev.com (the same setup script then adds the webhook).

---

## Part 2: Live account (before launch, after the legal pages are up)

Don't start this until kilndev.com has these **published and linked from the site's menu or
footer**, because Paddle reviews the live site and rejects a domain that's missing them:

- [ ] A pricing page (the /buy page with the three plans counts) and what Kiln Pro includes
- [ ] Terms and Conditions that name your business (your legal or sole-proprietor name)
- [ ] Refund Policy
- [ ] Privacy Policy
- [ ] All three legal pages say **Paddle is the merchant of record** (Paddle is the seller,
      handles payment, tax and invoices)
- [ ] HTTPS, and a working contact address

Then:

1. **Sign up** for a live account at <https://login.paddle.com/signup> (dashboard:
   <https://vendors.paddle.com>). It's separate from the sandbox: nothing carries over.
2. **Verification** (in the dashboard, only you can do it). Paddle checks, in order:
   - **Domain review:** Checkout → Website approval → add `kilndev.com`. Most are approved
     automatically; a manual review is quoted at 5–7 business days.
   - **Business details:** whether you're selling as a sole proprietor or a company, legal name,
     address.
   - **Identity:** photo ID for you as the owner.
   - **Final manual review** by Paddle.

   Have ready: legal business name and address, a photo ID, and a description of Kiln
   ("desktop app for local web development, sold as annual / lifetime / team licences").
3. **Payouts:** add the bank account Paddle pays into. You can start selling before this is
   set up, but Paddle can't pay you until it is. Expect to be asked for a tax form too (as a
   Canadian seller, likely a W-8BEN, or W-8BEN-E for a company).
4. **Tax display:** decide whether your prices include tax or have it added on top. The site
   currently says "Total before tax", i.e. tax on top. Tell Claude if you want it the other way.
5. **Live keys:** repeat Part 1 steps 2–4 in the live dashboard. Live keys start
   `pdl_live_apikey_` and `live_`. These go into the **server's** MODX (kilndev.com), with
   `kiln.paddle_env` = `live`. Then tell Claude, who reruns the setup script against live
   (fresh live products + webhook) and makes sure `kiln.payments_test_mode` is off.
6. **One real purchase:** buy a Lifetime licence yourself, check the key works in Kiln, refund it
   from Extras → Kiln Licences, check it gets revoked. Then open sales.

---

## Good to know

- **Fees:** 5% + 50¢ per sale at the time of choosing (check paddle.com/pricing). Paddle files
  and pays the sales tax / VAT / GST for every country, so you don't register anywhere for it.
- **Buyers see** a receipt from Paddle (the tax invoice) as well as Kiln's own email with the
  keys, and the charge shows as `PADDLE.NET*` on their statement.
- **Sales stay off** until you flip them: the switch at the top of Extras → Kiln Licences
  (Coming soon / Open / Paused). Connecting Paddle doesn't open sales by itself.
- **Prices** stay in the site's settings (`kiln.price_*`), not in Paddle. Change them in one place.
