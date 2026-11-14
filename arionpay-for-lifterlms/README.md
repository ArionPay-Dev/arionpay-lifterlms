# ArionPay for LifterLMS

Sell courses and memberships for crypto (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) in LifterLMS through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Tested on WordPress 7.1.4 with LifterLMS 11.0.1 and PHP 8.3. The tests covered one-time and monthly plans, renewals, a lost webhook, payment tolerance, Payment Radar, late payments, status notes, webhook checks and the settings page.

## Install

1. **Plugins > Add New > Upload Plugin**, choose `arionpay-for-lifterlms.zip`, install and activate.
2. **LifterLMS > Settings > Checkout > ArionPay (crypto)**: tick **Enable**, keep **Test mode** on for now, fill in the keys and save.

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

The secrets are shown only partly hidden; saving the page unchanged keeps them. You can also put them in `wp-config.php` as `LLMS_ARIONPAY_API_SECRET` and `LLMS_ARIONPAY_WEBHOOK_SECRET`. Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (the address is shown on the settings page).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## How it works

- At checkout the student chooses **Crypto (Bitcoin, USDT and more)** and goes to the ArionPay page. The order waits as **Pending**.
- When ArionPay confirms the full payment, LifterLMS records the transaction and enrolls the student: one-time plans become **Completed**, recurring plans **Active**.
- The student's order page shows **Pay with crypto** while a payment is open. Opening that page also checks with ArionPay, so a lost webhook is caught there too.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically; anything else you decide. If you accept it, the payment is recorded here too.
- A late payment still counts.
- "Payment seen", partial, expired and "needs review" updates become order notes.
- A transaction is recorded only for the invoice made for it: same amount and currency, and test or live like the order. Each invoice is recorded once.

## Recurring plans

Crypto cannot charge a student automatically. When a recurring payment is due, the plugin:

- makes a new invoice;
- puts the order **On Hold**, which pauses access;
- emails the student a payment link, which also appears on their order page.

When the student pays, the order is **Active** again. Trials work as in LifterLMS: a free trial needs no payment, and a paid trial is the first invoice.

## Good to know

- LifterLMS uses one site currency. It must be one ArionPay takes: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR.
- Refunds are made from your own wallet; then record the refund on the order by hand.
- The plugin sends ArionPay the order number, amount, currency and product name. It does not send the student's name or email.
