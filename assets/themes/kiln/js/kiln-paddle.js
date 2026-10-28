/* Paddle's overlay checkout on /buy (see KilnWeb\BuyController::paddleCheckout).
   The order is only ever marked paid by Paddle's webhook or by the order page
   asking Paddle; this script just opens the window and reacts to it closing. */
(function () {
  var el = document.getElementById('k-paddle');
  if (!el || !window.Paddle) {
    return;
  }
  var d = el.dataset, done = false;
  var closed = el.querySelector('.k-checkout__closed');
  if (d.env === 'sandbox') {
    Paddle.Environment.set('sandbox');
  }
  Paddle.Initialize({
    token: d.token,
    eventCallback: function (ev) {
      if (ev.name === 'checkout.completed') {
        done = true;
      } else if (ev.name === 'checkout.closed' && !done && closed) {
        closed.hidden = false;
      }
    }
  });
  var open = function () {
    if (closed) {
      closed.hidden = true;
    }
    Paddle.Checkout.open({
      transactionId: d.txn,
      settings: {
        displayMode: 'overlay',
        theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
        locale: 'en',
        successUrl: d.success
      }
    });
  };
  var btn = el.querySelector('[data-paddle-open]');
  if (btn) {
    btn.addEventListener('click', open);
  }
  if (d.auto === '1') {
    open();
  }
})();
