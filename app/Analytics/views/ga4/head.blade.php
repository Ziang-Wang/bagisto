@php
    $ga4Id = config('analytics.ga4.measurement_id');

    [$ga4CheckoutEvent, $ga4CheckoutPayload] = match (true) {
        request()->routeIs('shop.checkout.onepage.index') => ['begin_checkout', \App\Analytics\Ga4::beginCheckout()],
        request()->routeIs('shop.checkout.onepage.success') => ['purchase', \App\Analytics\Ga4::purchase()],
        default => [null, null],
    };
@endphp

<script>
    window.dataLayer = window.dataLayer || [];

    function gtag() {
        dataLayer.push(arguments);
    }

    /*
     * Consent Mode v2. Visitors in the EEA, the UK and Switzerland only send
     * cookieless pings until they accept cookies in the store's GDPR banner
     * (Configure → General → GDPR); everyone else is measured by default.
     */
    gtag('consent', 'default', {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        wait_for_update: 500,
        region: [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
            'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
            'IS', 'LI', 'NO', 'GB', 'CH',
        ],
    });

    gtag('consent', 'default', {
        analytics_storage: 'granted',
        ad_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'granted',
    });

    (function () {
        function cookie(name) {
            const match = document.cookie.match('(?:^|; )' + name + '=([^;]*)');

            return match ? decodeURIComponent(match[1]) : null;
        }

        // The banner stores cookie-consent=1 (accept) or 0 (reject); the preferences
        // page stores cookie-consent=custom plus a true/false cookie per category
        // ("measurements", "targeting_advertising"), which always wins.
        function applyStoredChoice() {
            const choice = cookie('cookie-consent');
            const measurement = cookie('measurements');
            const advertising = cookie('targeting_advertising');

            if (choice === null && measurement === null && advertising === null) {
                return;
            }

            const state = (value) => (value === 'true' || (choice === '1' && value !== 'false')) ? 'granted' : 'denied';

            gtag('consent', 'update', {
                analytics_storage: state(measurement),
                ad_storage: state(advertising),
                ad_user_data: state(advertising),
                ad_personalization: state(advertising),
            });
        }

        applyStoredChoice();

        // "Accept" doesn't reload the page, so re-read the cookies after banner clicks.
        document.addEventListener('click', (event) => {
            if (event.target.closest && event.target.closest('.js-cookie-consent')) {
                setTimeout(applyStoredChoice, 0);
            }
        }, true);
    })();

    gtag('js', new Date());
    gtag('config', @json($ga4Id));

    /*
     * add_to_cart. Every add-to-cart button posts to /api/checkout/cart through
     * axios (XHR). Product names and prices come from the product page
     * (view_item) and from the /api/products responses behind product cards.
     */
    (function () {
        const products = window.ga4Products = window.ga4Products || {};
        const urlKeys = {};
        const currency = @json(core()->getBaseCurrencyCode());
        const open = XMLHttpRequest.prototype.open;
        const send = XMLHttpRequest.prototype.send;

        // Listing responses only carry the formatted price, e.g. "$1,299.99".
        function amount(formatted) {
            return parseFloat(String(formatted ?? '').replace(/[^0-9.]/g, '')) || 0;
        }

        function requestFields(body) {
            if (body instanceof FormData) {
                return { product_id: body.get('product_id'), quantity: body.get('quantity') };
            }

            try {
                return typeof body === 'string' ? JSON.parse(body) : {};
            } catch (e) {
                return {};
            }
        }

        XMLHttpRequest.prototype.open = function (method, url) {
            this.ga4Method = String(method).toUpperCase();
            this.ga4Path = new URL(url, location.href).pathname;

            return open.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function (body) {
            const xhr = this;

            if (xhr.ga4Method === 'GET' && xhr.ga4Path === '/api/products') {
                xhr.addEventListener('load', () => {
                    try {
                        (JSON.parse(xhr.responseText).data || []).forEach((product) => {
                            products[product.id] = {
                                item_id: String(product.id),
                                item_name: product.name,
                                price: amount(product.min_price),
                            };

                            urlKeys[product.id] = product.url_key;
                        });
                    } catch (e) {}
                });
            } else if (xhr.ga4Method === 'POST' && xhr.ga4Path === '/api/checkout/cart') {
                const fields = requestFields(body);

                xhr.addEventListener('load', () => {
                    let response = {};

                    try {
                        response = JSON.parse(xhr.responseText);
                    } catch (e) {}

                    if (xhr.status !== 200 || ! response.data?.items || ! fields.product_id) {
                        return;
                    }

                    const quantity = parseInt(fields.quantity, 10) || 1;
                    const item = Object.assign({ item_id: String(fields.product_id) }, products[fields.product_id], { quantity });

                    // The cart line has the exact unit price (e.g. a configurable's chosen option).
                    const line = response.data.items.find((cartItem) => urlKeys[fields.product_id] && cartItem.product_url_key === urlKeys[fields.product_id]);

                    if (line) {
                        item.price = Number(line.price);
                    }

                    gtag('event', 'add_to_cart', {
                        currency,
                        value: Math.round((item.price || 0) * quantity * 100) / 100,
                        items: [item],
                    });
                });
            }

            return send.apply(this, arguments);
        };
    })();

    @if ($ga4CheckoutPayload)
        gtag('event', @json($ga4CheckoutEvent), @json($ga4CheckoutPayload));
    @endif
</script>

<script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4Id }}"></script>
