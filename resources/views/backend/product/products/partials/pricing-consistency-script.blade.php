<script>
    (function () {
        const cost = document.getElementById('coremarket-cost-price');
        const regular = document.getElementById('coremarket-regular-price');
        const margin = document.getElementById('coremarket-margin-percent');
        const sale = document.getElementById('coremarket-sale-price');
        const legacyDiscount = document.querySelector('input[name="discount"]');
        const legacyDiscountType = document.querySelector('select[name="discount_type"]');
        if (!cost || !regular || !margin) return;

        const roundMoney = value => (Number(value) || 0).toFixed(2);
        const updateRegular = () => {
            if (margin.value === '') return;
            regular.value = roundMoney((Number(cost.value) || 0) * (1 + (Number(margin.value) || 0) / 100));
        };
        const updateMargin = () => {
            const costValue = Number(cost.value) || 0;
            if (costValue <= 0 || regular.value === '') {
                margin.value = '';
                return;
            }
            margin.value = (((Number(regular.value) - costValue) / costValue) * 100).toFixed(2);
        };
        const validateSale = () => {
            sale.setCustomValidity(
                sale.value !== '' && Number(sale.value) > Number(regular.value)
                    ? @json(translate('Sale price must not exceed the regular price.'))
                    : ''
            );
        };
        const syncLegacyDiscount = () => {
            if (!legacyDiscount || !legacyDiscountType) return;
            legacyDiscount.value = sale.value === ''
                ? '0'
                : roundMoney(Math.max(0, (Number(regular.value) || 0) - (Number(sale.value) || 0)));
            legacyDiscountType.value = 'amount';
            if (window.jQuery && typeof window.jQuery(legacyDiscountType).selectpicker === 'function') {
                window.jQuery(legacyDiscountType).selectpicker('refresh');
            }
        };
        const syncSaleFromLegacyDiscount = () => {
            if (!legacyDiscount || !legacyDiscountType || Number(legacyDiscount.value) <= 0) {
                sale.value = '';
                validateSale();
                return;
            }
            const regularValue = Number(regular.value) || 0;
            const discountValue = Number(legacyDiscount.value) || 0;
            sale.value = roundMoney(Math.max(0, legacyDiscountType.value === 'percent'
                ? regularValue * (1 - discountValue / 100)
                : regularValue - discountValue));
            validateSale();
        };

        cost.addEventListener('input', updateMargin);
        regular.addEventListener('input', () => {
            updateMargin();
            validateSale();
            syncLegacyDiscount();
        });
        margin.addEventListener('input', () => {
            updateRegular();
            validateSale();
            syncLegacyDiscount();
        });
        sale?.addEventListener('input', () => {
            validateSale();
            syncLegacyDiscount();
        });
        legacyDiscount?.addEventListener('input', syncSaleFromLegacyDiscount);
        legacyDiscountType?.addEventListener('change', syncSaleFromLegacyDiscount);
    })();
</script>
