jQuery(function($){
    if (typeof wcofStripeGateway === 'undefined') {
        return;
    }
    var stripe = Stripe(wcofStripeGateway.pk);
    var elements = stripe.elements();
    var card = elements.create('card');
    card.mount('#wcof-stripe-card-element');

    $('form.checkout').on('checkout_place_order_' + wcofStripeGateway.gateway, function(){
        var deferred = $.Deferred();
        stripe.createPaymentMethod({type:'card', card:card}).then(function(result){
            if(result.error){
                if($('.woocommerce-NoticeGroup-checkout').length){
                    $('.woocommerce-NoticeGroup-checkout').remove();
                }
                $('form.checkout').append('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error">'+result.error.message+'</div></div>');
                deferred.reject();
            }else{
                $('#wcof_stripe_pm').val(result.paymentMethod.id);
                deferred.resolve();
            }
        });
        return deferred.promise();
    });
});
