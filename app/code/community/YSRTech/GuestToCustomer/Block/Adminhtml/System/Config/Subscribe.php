<?php
/**
 * YSRTech_GuestToCustomer
 *
 * The button that puts every customer who has never been on the newsletter
 * list onto it, with its progress shown beside it.
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */
class YSRTech_GuestToCustomer_Block_Adminhtml_System_Config_Subscribe extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $h = Mage::helper('ysrtech_guesttocustomer');

        $config = Mage::helper('core')->jsonEncode(array(
            'count'   => $this->getUrl('adminhtml/guesttocustomer/subscribeCount'),
            'run'     => $this->getUrl('adminhtml/guesttocustomer/subscribeRun'),
            'formKey' => $this->getFormKey(),
            't'       => array(
                'none'    => $h->__('Nobody to add: every customer is on the newsletter list already, subscribed or not.'),
                'confirm' => $h->__("%s customers have never been on the newsletter list.\n\nThey will be added as subscribed. Customers who unsubscribed, or are on the list in any other state, are skipped. No email is sent to anybody.\n\nOnly do this if your customers have agreed to marketing email.\n\nSubscribe them?"),
                'working' => $h->__('Working… %1 of %2 customers subscribed. Keep this page open.'),
                'done'    => $h->__('Done. %1 customers subscribed. Customers already on the list were left as they were.'),
                'stopped' => $h->__('Stopped: %s Press the button to carry on from where it stopped.'),
            ),
        ));

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')->setData(array(
            'id'      => 'ysrtech_g2c_subscribe',
            'label'   => $h->__('Subscribe All Customers Who Never Subscribed'),
            'type'    => 'button',
            'onclick' => 'ysrtechGuestToCustomerSubscribe(this)',
        ))->toHtml();

        return $button . '<p id="ysrtech_g2c_subscribe_status" style="margin:8px 0 0;max-width:520px"></p>'
            . '<script type="text/javascript">//<![CDATA[
(function () {
    var cfg = ' . $config . ';
    var running = false;

    function post(url, fields) {
        var body = new FormData();
        body.append("form_key", cfg.formKey);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(url, {method: "POST", body: body, credentials: "same-origin", headers: {"X-Requested-With": "XMLHttpRequest"}})
            .then(function (r) { return r.text(); })
            .then(function (text) {
                var json = null;
                try { json = JSON.parse(text); } catch (e) {}
                if (!json || !json.ok) { throw new Error((json && json.message) || "You have been signed out, or this page has been open too long. Reload it and try again."); }
                return json;
            });
    }

    function num(n) { return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ","); }

    window.ysrtechGuestToCustomerSubscribe = function (button) {
        if (running) { return; }
        var status = document.getElementById("ysrtech_g2c_subscribe_status");
        var total = 0;
        var say = function (text) { status.textContent = text; };
        var finish = function () { running = false; button.disabled = false; button.classList.remove("disabled"); };

        running = true; button.disabled = true; button.classList.add("disabled");

        post(cfg.count).then(function (c) {
            if (!c.customers) { say(cfg.t.none); finish(); return; }
            if (!window.confirm(cfg.t.confirm.replace("%s", num(c.customers)))) { say(""); finish(); return; }

            var step = function (after) {
                return post(cfg.run, {after: after}).then(function (r) {
                    total += r.subscribed;
                    if (r.done) { say(cfg.t.done.replace("%1", num(total))); finish(); return; }
                    say(cfg.t.working.replace("%1", num(total)).replace("%2", num(c.customers)));
                    return step(r.after);
                });
            };

            say(cfg.t.working.replace("%1", "0").replace("%2", num(c.customers)));
            return step(0);
        }).catch(function (error) {
            say(cfg.t.stopped.replace("%s", error.message));
            finish();
        });
    };
}());
//]]></script>';
    }

    /**
     * The button belongs to no scope.
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }
}
