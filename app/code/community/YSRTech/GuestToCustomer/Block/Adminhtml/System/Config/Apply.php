<?php
/**
 * YSRTech_GuestToCustomer
 *
 * The button that applies the module to the guest orders already in the
 * store, with its progress shown beside it.
 *
 * @category   YSRTech
 * @package    YSRTech_GuestToCustomer
 */
class YSRTech_GuestToCustomer_Block_Adminhtml_System_Config_Apply extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $h    = Mage::helper('ysrtech_guesttocustomer');
        $core = Mage::helper('core');

        $config = $core->jsonEncode(array(
            'count'   => $this->getUrl('adminhtml/guesttocustomer/count'),
            'run'     => $this->getUrl('adminhtml/guesttocustomer/run'),
            'formKey' => $this->getFormKey(),
            't'       => array(
                'none'     => $h->__('There are no guest orders: everybody who has ordered already has an account.'),
                'confirm'  => $h->__("There are %s guest orders.\n\nEach guest with no account will be given one, and their orders linked to it. No email is sent to them.\n\nUnsaved changes to the settings on this page are not used: save first if you changed any.\n\nStart?"),
                'working'  => $h->__('Working… %1 of %2 orders looked at, %3 accounts made, %4 orders linked. Keep this page open.'),
                'done'     => $h->__('Done. %1 orders looked at: %3 accounts made, %4 orders linked, %5 left as they are, %6 errors.'),
                'stopped'  => $h->__('Stopped: %s Press the button to carry on from where it stopped.'),
                'errors'   => $h->__('Details of the errors are in var/log/ysrtech_guesttocustomer.log.'),
                'names'    => $h->__('%1 newsletter subscribers were given their name from their account.'),
            ),
        ));

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')->setData(array(
            'id'      => 'ysrtech_g2c_apply',
            'label'   => $h->__('Apply To All Existing Guest Orders'),
            'type'    => 'button',
            'onclick' => 'ysrtechGuestToCustomerApply(this)',
        ))->toHtml();

        return $button . '<p id="ysrtech_g2c_status" style="margin:8px 0 0;max-width:520px"></p>'
            . '<script type="text/javascript">//<![CDATA[
(function () {
    var cfg = ' . $config . ';
    var running = false;

    function post(url, fields) {
        var body = new FormData();
        body.append("form_key", cfg.formKey);
        Object.keys(fields || {}).forEach(function (k) { if (fields[k] !== null) { body.append(k, fields[k]); } });
        return fetch(url, {method: "POST", body: body, credentials: "same-origin", headers: {"X-Requested-With": "XMLHttpRequest"}})
            .then(function (r) { return r.text(); })
            .then(function (text) {
                var json = null;
                try { json = JSON.parse(text); } catch (e) {}
                if (!json || !json.ok) { throw new Error((json && json.message) || "You have been signed out, or this page has been open too long. Reload it and try again."); }
                return json;
            });
    }

    function fill(text, values) {
        return values.reduce(function (out, v, i) { return out.replace("%" + (i + 1), String(v).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ",")); }, text);
    }

    window.ysrtechGuestToCustomerApply = function (button) {
        if (running) { return; }
        var status = document.getElementById("ysrtech_g2c_status");
        var total = {scanned: 0, linked: 0, created: 0, errors: 0};

        var say = function (text) { status.textContent = text; };
        var finish = function () { running = false; button.disabled = false; button.classList.remove("disabled"); };

        running = true; button.disabled = true; button.classList.add("disabled");

        post(cfg.count).then(function (c) {
            if (!c.orders) {
                // Nothing to link, but the list may still be missing names from an earlier run
                return post(cfg.run, {below: null}).then(function (r) {
                    say(cfg.t.none + (r.subscribers && r.subscribers.named ? " " + fill(cfg.t.names, [r.subscribers.named]) : ""));
                    finish();
                });
            }
            if (!window.confirm(cfg.t.confirm.replace("%s", String(c.orders).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ",")))) { say(""); finish(); return; }

            var step = function (below) {
                return post(cfg.run, {below: below}).then(function (r) {
                    ["scanned", "linked", "created", "errors"].forEach(function (k) { total[k] += r[k]; });
                    if (r.done) {
                        say(fill(cfg.t.done, [total.scanned, c.orders, total.created, total.linked, total.scanned - total.linked - total.errors, total.errors])
                            + (r.subscribers && r.subscribers.named ? " " + fill(cfg.t.names, [r.subscribers.named]) : "")
                            + (total.errors ? " " + cfg.t.errors : ""));
                        finish();
                        return;
                    }
                    say(fill(cfg.t.working, [total.scanned, c.orders, total.created, total.linked]));
                    return step(r.below);
                });
            };

            say(fill(cfg.t.working, [0, c.orders, 0, 0]));
            return step(null);
        }).catch(function (error) {
            say(cfg.t.stopped.replace("%s", error.message));
            finish();
        });
    };
}());
//]]></script>';
    }

    /**
     * The button belongs to no scope: drop the "use default" box and the
     * scope label a field would have.
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
