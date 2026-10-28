# Selling Kiln Pro through Paddle

Paddle is the **merchant of record**: it is the legal seller, charges the buyer's card, adds and
files sales tax / VAT / GST for every country, handles refunds, chargebacks and invoices, and
pays out to Rob minus its fee (5% + 50¢ a sale at the time of choosing, 2026-10-27; check
paddle.com/pricing).

The site does the rest: cart, accounts, licence keys, renewals of keys, receipts with the keys.

## How it fits together

| Step | What happens | Code |
|---|---|---|
| Check out | Site finds or makes the Paddle customer for the buyer's email, then creates a Paddle transaction with the **site's own prices** (`kiln.price_*`) on the three Kiln products, tagged `custom_data.kiln_order = K-…` | `PaddleProvider::createCheckout` |
| Pay | `/buy?checkout=K-…` opens Paddle's overlay window (Paddle.js). The buyer pays there; Paddle adds tax | `buy.PaddleCheckout`, `js/kiln-paddle.js` |
| Paid | Paddle's `transaction.completed` webhook fulfils the order. The order page also asks Paddle directly, so the buyer never waits on a slow webhook | `Shop::reconcile`, `Shop::webhook` |
| Renewal | Paddle charges yearly by itself; its `transaction.completed` (origin `subscription_recurring`) re-signs the annual keys and moves the team on | `Shop::renew` |
| Cancel renewal | Buyer clicks **Manage billing** on their account page → Paddle's customer portal | `AccountController` `do=billing` |
| Refund | Extras → Kiln Licences → order → Refund asks Paddle to refund the latest charge in full. When Paddle **approves** it (webhook `adjustment.*`), every key from the order is revoked and the subscription is cancelled. Refunds made in Paddle's own dashboard work the same way. Partial refunds change nothing on the site | `Shop::requestRefund`, `Shop::refund` |

Annual and Team are yearly subscriptions in Paddle; Lifetime is a one-off charge. A mixed cart is
one Paddle transaction (one subscription for the yearly items).

## Setting up (sandbox first)

Rob's own step-by-step version, including the live account's verification, is
`PADDLE-SIGNUP.md`.

1. **Sandbox account:** sign up at sandbox-login.paddle.com/signup (free, separate from the live
   account); the dashboard is sandbox-vendors.paddle.com.
2. **Keys:** in Paddle → Developer tools → Authentication, create
   - an **API key** (starts `pdl_sdbx_apikey_`) with write access to customers, transactions,
     adjustments, subscriptions, customer portal sessions, products and notification settings;
   - a **client-side token** (starts `test_`).
3. **Default payment link:** Paddle → Checkout → Checkout settings → default payment link =
   `https://kilndev.com/buy` (Paddle won't create transactions without one; its "update your card"
   emails link there, and /buy opens Paddle.js for them).
4. **Tax display:** decide in Paddle's settings whether prices include tax. The site shows
   "Total before tax" and expects tax to be added on top.
5. **MODX settings** (System Settings, area Paddle): `kiln.paddle_env` = `sandbox`,
   `kiln.paddle_api_key`, `kiln.paddle_client_token`.
6. **Products and webhook:** on the server, as the site owner:
   ```
   php core/components/kiln/_build/paddle-setup.php --webhook=https://kilndev.com/payment-webhook?provider=paddle
   ```
   This makes the three products (Kiln Pro · Annual / Lifetime / Team) and the notification
   destination, and saves the product ids and the webhook secret into the settings. Safe to re-run.
   On kilndev.test leave `--webhook` out (Paddle can't reach it).
7. **Switch it on:** `kiln.payment_provider` = `paddle`. The top of Extras → Kiln Licences now
   says "Paddle sandbox" (or lists exactly what is still missing). Open sales there.
8. **Try it:** buy on /buy with Paddle's test card 4242 4242 4242 4242, any future expiry, CVC 100.
   In the sandbox Paddle approves refunds about every ten minutes.

## Going live

1. Paddle's **live** account must be approved first: Paddle reviews the business and the website
   (published prices, Terms, Refund Policy, Privacy Policy, product description, a working contact).
2. Repeat steps 2–6 in the live dashboard (vendors.paddle.com) with live keys (`pdl_live_apikey_`,
   `live_`) and `kiln.paddle_env` = `live`. The script makes fresh live products.
3. Make sure `kiln.payments_test_mode` is **off**.
4. Buy one Lifetime for real, check the key, refund it from the manager, check it is revoked.

## Local testing

`php core/components/kiln/tests/paddle.php` runs the whole flow against a stand-in Paddle
(`tests/paddle-mock.php`, started on 127.0.0.1:8790 by the test). It points the site at it with a
temporary `kiln.paddle_api_url` setting, honoured only in the sandbox environment, and removes it
afterwards.

With a real sandbox on kilndev.test, first payments still complete (the order page asks Paddle),
but renewals and refunds need webhooks, which need a public URL: try those on the server.
