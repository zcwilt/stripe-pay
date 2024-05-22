const StripeScript = document.createElement('script');
StripeScript.src = "https://js.stripe.com/v3/";
document.head.appendChild(StripeScript);
console.log(stripePaymentAmount);
StripeScript.addEventListener("load", () => {
    console.log("stripe loaded");
    if (stripeAlwaysShowForm) {
        $('#stripepay-intent-payment-element').show();
    }
    if ($('#pmt-stripepay').is(':checked')) {
        $('#stripepay-intent-payment-element').show();
    }
    $('input[name="payment"]').on('change', function () {
        if ($('#pmt-stripepay').is(':checked')) {
            $('#stripepay-intent-payment-element').show();
        } else {
            $('#stripepay-intent-payment-element').hide();
        }
    });
    const stripe = Stripe(stripePublishableKey);
    const elements = stripe.elements({clientSecret: stripeSecretKey});
    const paymentElement = elements.create('payment', {
        defaultValues: {
            billingDetails: {
                address: {
                    country: stripeBillingCountry,  // Set default country
                    postal_code: stripeBillingPostcode  // Set default postal code
                }
            }
        }
    });
    paymentElement.mount('#stripepay-intent-payment-element');
    const form = $('form[name="checkout_payment"]');
    const hiddenButton = document.createElement('button');
    hiddenButton.type = 'button';
    hiddenButton.id = 'stripe-submit-button';
    hiddenButton.style.display = 'none';
    form.append(hiddenButton);
    form.on('submit', function (event) {
        event.preventDefault();
        hiddenButton.click();
    });
    $('#stripe-submit-button').on('click', async function () {
        const {error} = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: 'https://your-website.com/confirm_payment.php',
            },
            redirect: 'if_required',
        });

        if (error) {
            console.error(error);
            $('#stripepay-intent-error-message').text(error.message);
        } else {
            form.off('submit'); // Remove the submit handler to avoid recursion
            form.submit(); // Submit the form normally
        }
    });
});

