<?php

use CDEK\CdekApi;
use CDEK\CdekOrderMetaRepository;
use CDEK\model\Tariffs;
use CDEK\Service;
use CDEK\Settings;

require_once(DIR_SYSTEM . 'library/cdek_official/vendor/autoload.php');

class ControllerExtensionShippingCdekOfficial extends Controller
{

    public function index()
    {
        if (isset($this->request->get['cdekRequest'])) {
            $this->load->model('setting/setting');
            $param = $this->model_setting_setting->getSetting('cdek_official');
            $settings = new Settings();
            $settings->init($param);
            $cdekApi = new CdekApi($this->registry, $settings);
            $authData = $cdekApi->getData();
            $service = new Service($authData['client_id'], $authData['client_secret'], $authData['base_url']);
            $service->process($this->request->get, file_get_contents('php://input'));
        }
    }

    public function cdek_official_checkout_shipping_after(&$route, &$data, &$output)
    {
        $code = [];
        $mapLayout = [];
        if (array_key_exists('cdek_official', $data['shipping_methods'])) {
            foreach ($data['shipping_methods']['cdek_official']['quote'] as $key => $quote) {
                $separate = explode('_', $key);
                $tariffCode = end($separate);
                $tariffModel = new Tariffs();
                if ($tariffModel->getDirectionByCode((int)$tariffCode) === 'store' || $tariffModel->getDirectionByCode((int)$tariffCode) === 'postamat') {
                    $code[] = $quote['code'];
                    $mapLayout[$quote['code']] = $quote['extra'];
                    unset($data['shipping_methods']['cdek_official']['quote'][$key]['extra']);
                }
            }
        }

        if (!empty($code)) {
            $cdekBlock = '<p><strong>CDEK Official Shipping</strong></p>';
            $pvzCode = '
                <input class="cdek_official_pvz_code_address" id="cdek_official_pvz_code_address" name="cdek_official_pvz_code_address" value="" style="display: none; width: 250px;">
                <input type="hidden" id="cdek_official_pvz_code" name="cdek_official_pvz_code" value="">
            ';
            $this->searchAndReplace($output, $cdekBlock, $pvzCode);
            foreach ($code as $quoteCode) {
                $cdekQuoteLayoutMap = $mapLayout[$quoteCode];
                $cdekQuoteBlockPattern = '/<div class="radio">.*?value="' . preg_quote($quoteCode, '/') . '".*?<\/label>/s';

                $output = preg_replace_callback($cdekQuoteBlockPattern, function ($matches) use ($cdekQuoteLayoutMap) {
                    return substr($matches[0], 0, -8) . $cdekQuoteLayoutMap . "</label>";
                }, $output);
            }
        }
    }

    public function cdek_official_checkout_checkout_after(&$route, &$data, &$output)
    {
        $header = "<head>";
        $map = $this->registry->get('load')->view('extension/shipping/cdek_official_map_script');
        $this->searchAndReplace($output, $header, $map);

        $btnShippingMethod = "data: $('#collapse-shipping-method input[type=\'radio\']:checked, #collapse-shipping-method textarea')";
        $btnShippingMethodWithHide = "data: $('#collapse-shipping-method input[type=\'radio\']:checked, #collapse-shipping-method textarea, #collapse-shipping-method input[type=\'hidden\']')";
        $output = str_replace($btnShippingMethod, $btnShippingMethodWithHide, $output);
    }

    public function cdek_official_checkout_shipping_controller_before(&$route, &$data, &$output)
    {
        $shippingMethod = $this->request->post['shipping_method'];
        $shippingMethodExplode = explode('.', $shippingMethod);
        $shippingMethodName = $shippingMethodExplode[0];
        if ($shippingMethodName === 'cdek_official') {
            $shippingMethodTariff = $shippingMethodExplode[1];
            $shippingMethodTariffExplode = explode('_', $shippingMethodTariff);
            $tariffCode = end($shippingMethodTariffExplode);
            $tariffModel = new Tariffs();
            if ($tariffModel->getDirectionByCode((int)$tariffCode) === 'store' || $tariffModel->getDirectionByCode((int)$tariffCode) === 'postamat') {
                if (isset($this->request->post['cdek_official_pvz_code']) && !empty($this->request->post['cdek_official_pvz_code'])) {
                    $cityName = $this->cart->customer->session->data['shipping_address']['city'];
                    $this->load->model('setting/setting');
                    $param = $this->model_setting_setting->getSetting('cdek_official');
                    $settings = new Settings();
                    $settings->init($param);
                    $cdekApi = new CdekApi($this->registry, $settings);
                    $city = $cdekApi->getCity($cityName);
                    $cityCodeByPvz = $cdekApi->getCityCodeByPvz($this->request->post['cdek_official_pvz_code']);
                    if ($city[0]->code === $cityCodeByPvz) {
                        $this->session->data['cdek_official_pvz_code'] = $this->request->post['cdek_official_pvz_code'];
                    } else {
                        $this->load->language('extension/shipping/cdek_official');
                        $json['error']['warning'] = $this->language->get('cdek_pvz_not_from_selected_city');
                        $this->response->addHeader('Content-Type: application/json');
                        $this->response->setOutput(json_encode($json));
                    }
                } else {
                    $this->load->language('extension/shipping/cdek_official');
                    $json['error']['warning'] = $this->language->get('cdek_pvz_not_found');
                    $this->response->addHeader('Content-Type: application/json');
                    $this->response->setOutput(json_encode($json));
                }
            }
        }
    }

    public function cdek_official_checkout_confirm_after()
    {
        if (isset($this->session->data['order_id']) && isset($this->session->data['cdek_official_pvz_code'])) {
            $cdekPvzCode = $this->session->data['cdek_official_pvz_code'];
            CdekOrderMetaRepository::insertPvzCode($this->db, DB_PREFIX, $this->session->data['order_id'], $cdekPvzCode);
            unset($this->session->data['cdek_official_pvz_code']);
        }
    }

    private function searchAndReplace(&$output, $search, $replace)
    {
        $pos = strpos($output, $search);

        if ($pos !== false) {
            $insertPos = $pos + strlen($search);
            $output = substr_replace($output, $replace, $insertPos, 0);
        }

    }
}