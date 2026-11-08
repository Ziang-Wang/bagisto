<script>
    (function () {
        const payload = @json(\App\Analytics\Ga4::viewItem($product));

        // Lets the add_to_cart tracker in the <head> name the item.
        (window.ga4Products = window.ga4Products || {})[payload.items[0].item_id] = payload.items[0];

        gtag('event', 'view_item', payload);
    })();
</script>
