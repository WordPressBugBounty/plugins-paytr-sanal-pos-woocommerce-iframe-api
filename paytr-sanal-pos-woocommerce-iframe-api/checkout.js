const settings = window.wc.wcSettings.getSetting( 'paytr_payment_gateway_data', {} );

const paytr_payment_gateway = settings.paytr_payment_gateway;
const paytr_payment_gateway_eft = settings.paytr_payment_gateway_eft;
const ContentIframe = () => {
    return window.wp.htmlEntities.decodeEntities( paytr_payment_gateway.description);
};
const ContentEft = () => {
    return window.wp.htmlEntities.decodeEntities( paytr_payment_gateway_eft.description);
};
const LabelComponentIframe = () => {
    return window.wp.element.createElement(
        "div",
        {
            style: {
                display: "flex",
                alignItems: "center",
                gap: "5px",
            },
        },
        window.wp.element.createElement("img", {
            src: paytr_payment_gateway.icon,
            alt: `${paytr_payment_gateway.title}`,
            style: {
                width: "100px",
                marginRight: "10px",
                maxHeight: "20px",
                objectFit: "contain",
                display: paytr_payment_gateway.icon ? "block" : "none"
            },
        }),
        window.wp.element.createElement(
            "span", null, paytr_payment_gateway.title
        )
    )
}
const LabelComponentEft= () => {
    return window.wp.element.createElement(
        "div",
        {
            style: {
                display: "flex",
                alignItems: "center",
                gap: "5px",
            },
        },
        window.wp.element.createElement("img", {
            src: paytr_payment_gateway_eft.icon,
            alt: `${paytr_payment_gateway_eft.title}`,
            style: {
                width: "100px",
                marginRight: "10px",
                maxHeight: "20px",
                objectFit: "contain",
                display: paytr_payment_gateway_eft.icon ? "block" : "none"
            },
        }),
        window.wp.element.createElement(
            "span", null, paytr_payment_gateway_eft.title
        )
    )
}

const Block_Gateway_Iframe = {
    name: 'paytr_payment_gateway',
    label: window.wp.element.createElement(LabelComponentIframe, null),
    content: Object( window.wp.element.createElement )( ContentIframe, null ),
    edit: Object( window.wp.element.createElement )( ContentIframe, null ),
    canMakePayment: () => true,
    ariaLabel: window.wp.htmlEntities.decodeEntities( paytr_payment_gateway ? paytr_payment_gateway.title : ''),
};
const Block_Gateway_Eft = {
    name: 'paytr_payment_gateway_eft',
    label: window.wp.element.createElement(LabelComponentEft, null),
    content: Object( window.wp.element.createElement )( ContentEft, null ),
    edit: Object( window.wp.element.createElement )( ContentEft, null ),
    canMakePayment: () => true,
    ariaLabel: window.wp.htmlEntities.decodeEntities( paytr_payment_gateway_eft ? paytr_payment_gateway_eft.title : ''),
};

window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway_Iframe )
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway_Eft )
