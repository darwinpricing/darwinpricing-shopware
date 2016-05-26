<?php

/**
 * 2015 Darwin Pricing
 *
 * For support please visit www.darwinpricing.com
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the GNU Affero General Public License (AGPL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/agpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@darwinpricing.com so we can send you a copy immediately.
 *
 *  @author    Darwin Pricing <support@darwinpricing.com>
 *  @copyright 2015 Darwin Pricing
 *  @license   http://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License (AGPL 3.0)
 */

/**
 * Darwin Pricing plugin for Shopware
 */
class Shopware_Plugins_Frontend_DarwinPricing_Bootstrap extends Shopware_Components_Plugin_Bootstrap {

    /**
     * @return string
     * @throws Exception
     */
    public function getDescription() {
        static $description = null;
        if (!isset($description)) {
            $description = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'info.txt');
            if (false === $description) {
                throw new Exception('Plugin description not found');
            }
        }
        return $description;
    }

    public function getInfo() {
        return array(
            'label' => $this->getLabel(),
            'version' => $this->getVersion(),
            'author' => $this->getPluginInfo('author'),
            'copyright' => $this->getPluginInfo('copyright'),
            'description' => $this->getDescription(),
            'license' => $this->getPluginInfo('license'),
            'support' => 'support@darwinpricing.com',
            'link' => $this->getPluginInfo('link'),
        );
    }

    public function getLabel() {
        return $this->getPluginInfo('label', 'de');
    }

    public function getVersion() {
        return $this->getPluginInfo('currentVersion');
    }

    public function createForm() {
        $form = $this->Form();
        $parent = $this->Forms()->findOneBy(array('name' => 'Interface'));
        $form->setParent($parent);
        $form->setElement('text', 'serverUrl', array(
            'label' => 'API-Server',
            'description' => 'Die URL des Darwin Pricing API-Servers für Ihre Website',
            'value' => null,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));
        $form->setElement('text', 'clientId', array(
            'label' => 'Client-ID',
            'description' => 'Die Client-ID für Ihre Website',
            'value' => null,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));
        $form->setElement('text', 'clientSecret', array(
            'label' => 'Client-Schlüssel',
            'description' => 'Der geheime Client-Schlüssel für Ihre Website',
            'value' => null,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));
        $form->setElement('checkbox', 'disabled', array(
            'label' => 'Plugin deaktivieren',
            'description' => 'Darwin Pricing auf diesem Subshop deaktivieren',
            'value' => false,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP
        ));
        $form->setElement('button', 'darwinPricingSignUp', array(
            'label' => '<b>Jetzt kostenlosen Darwin Pricing-Account erhalten</b>',
            'handler' => 'function() { window.open("https://admin.darwinpricing.com/sign-up")}',
                )
        );
        $this->addFormTranslations($this->getFormTranslations());
    }

    public function install() {
        $this->registerEvents();
        $this->createForm();
        return array('success' => true, 'invalidateCache' => array('frontend'));
    }

    public function onPostDispatch(Enlight_Controller_ActionEventArgs $arguments) {
        try {
            $controller = $arguments->getSubject();
            $request = $controller->Request();
            $response = $controller->Response();
            $view = $controller->View();
            if (!$request->isDispatched() || $response->isException() || !$view->hasTemplate() || 'frontend' !== $request->getModuleName() || !$this->isActive()) {
                return;
            }
            if ('checkout' === $request->getControllerName() && 'finish' === $request->getActionName()) {
                $sOrderNumber = $view->getAssign('sOrderNumber');
                $visitorIp = $request->getClientIp(true);
                $this->trackOrder($sOrderNumber, $visitorIp);
            } else {
                $this->loadWidget($view);
            }
        } catch (\Exception $exception) {
            
        }
    }

    public function uninstall() {
        return array('success' => true, 'invalidateCache' => array('frontend'));
    }

    /**
     * @param array $sOrder
     * @return array
     */
    protected function addUnitCosts($sOrder) {
        if (is_array($sOrder) && isset($sOrder['details']) && is_array($sOrder['details'])) {
            foreach ($sOrder['details'] as &$item) {
                if (is_array($item) && isset($item['articleId'])) {
                    $sArticleDetail = $this->getArticleDetail($item['articleId']);
                    if (null !== $sArticleDetail) {
                        $unitCost = $this->getUnitCost($sArticleDetail);
                        if ($unitCost > 0) {
                            $item['unitCost'] = $unitCost;
                        }
                    }
                }
            }
        }
        return $sOrder;
    }

    /**
     * @param callable $workload
     */
    protected function executeOnShutdown(callable $workload) {
        register_shutdown_function(function() use ($workload) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $workload();
        });
    }

    /**
     * @param string $path
     * @param string|null $visitorIp
     * @return string
     */
    protected function getApiUrl($path, $visitorIp = null) {
        $config = $this->Config();
        $serverUrl = rtrim($config->serverUrl, '/');
        $apiUrl = $serverUrl . $path;
        $parameterList = array('platform' => 'shopware-' . Shopware::VERSION, 'site-id' => $config->clientId);
        if (null !== $visitorIp) {
            $parameterList['hash'] = $config->clientSecret;
            $parameterList['visitor-ip'] = $visitorIp;
        }
        $apiUrl .= '?' . http_build_query($parameterList, '', '&');
        return $apiUrl;
    }

    /**
     * @param int $sArticleId
     * @return \Shopware\Models\Article\Article|null
     */
    protected function getArticle($sArticleId) {
        /** @var \Shopware\Components\Api\Resource\Article $resource */
        $resource = \Shopware\Components\Api\Manager::getResource('article');
        $resource->setResultMode(\Shopware\Components\Api\Resource\Resource::HYDRATE_OBJECT);
        try {
            return $resource->getOne($sArticleId);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param int $sArticleId
     * @return \Shopware\Models\Article\Detail|null
     */
    protected function getArticleDetail($sArticleId) {
        $sArticle = $this->getArticle($sArticleId);
        if (null === $sArticle) {
            return null;
        }
        return $sArticle->getMainDetail();
    }

    /**
     * @return array
     */
    protected function getFormTranslations() {
        return array(
            'en_GB' => array(
                'serverUrl' => array(
                    'label' => 'API Server',
                    'description' => 'The URL of the Darwin Pricing API server for your website',
                ),
                'clientId' => array(
                    'label' => 'Client ID',
                    'description' => 'The client ID for your website',
                ),
                'clientSecret' => array(
                    'label' => 'Client Secret',
                    'description' => 'The client secret for your website',
                ),
                'disabled' => array(
                    'label' => 'Disable plugin',
                    'description' => 'Disable Darwin Pricing on this subshop',
                ),
                'darwinPricingSignUp' => array(
                    'label' => '<b>Get your free Darwin Pricing account</b>',
                ),
            )
        );
    }

    /**
     * @param string $sOrderNumber
     * @return array|null
     */
    protected function getOrder($sOrderNumber) {
        /** @var \Shopware\Components\Api\Resource\Order $resource */
        $resource = \Shopware\Components\Api\Manager::getResource('order');
        try {
            return $resource->getOneByNumber($sOrderNumber);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param string $key
     * @param string|null $language
     * @return string
     * @throws Exception
     */
    protected function getPluginInfo($key, $language = null) {
        $pluginInfo = $this->readPluginInfo();
        if (!isset($pluginInfo[$key])) {
            throw new Exception('Plugin ' . $key . ' not found');
        }
        if (null === $language) {
            return (string) $pluginInfo[$key];
        }
        if (!isset($pluginInfo[$key][$language])) {
            throw new Exception('Plugin ' . $key . ' not found');
        }
        return (string) $pluginInfo[$key][$language];
    }

    /**
     * @param \Shopware\Models\Article\Detail $sArticleDetail
     * @return float
     */
    protected function getUnitCost($sArticleDetail) {
        if (method_exists($sArticleDetail, 'getPurchasePrice')) {
            return $sArticleDetail->getPurchasePrice();
        }
        if (method_exists($sArticleDetail, 'getPrices')) {
            $sPrices = $sArticleDetail->getPrices();
            /** @var \Shopware\Models\Article\Price $sPrice */
            foreach ($sPrices as $sPrice) {
                if (method_exists($sPrice, 'getBasePrice')) {
                    $unitCost = $sPrice->getBasePrice();
                    if ($unitCost > 0) {
                        return $unitCost;
                    }
                }
            }
        }
        return 0.;
    }

    /**
     * @return bool
     */
    protected function isActive() {
        $config = $this->Config();
        return (empty($config->disabled) && !empty($config->serverUrl) && !empty($config->clientId) && !empty($config->clientSecret));
    }

    /**
     * @param string $src
     * @return string
     */
    protected function loadAsynchronousJavascript($src) {
        $src = json_encode($src);
        return "<script>(function(d,t,s,f){s=d.createElement(t);s.async=1;s.src={$src};f=d.getElementsByTagName(t)[0];f.parentNode.insertBefore(s,f)})(document,'script')</script>";
    }

    /**
     * @param Enlight_View_Default $view
     */
    protected function loadWidget(Enlight_View_Default $view) {
        $view->addTemplateDir(__DIR__ . '/Views/Common');
        $version = Shopware()->Shop()->getTemplate()->getVersion();
        if ($version >= 3) {
            $view->addTemplateDir(__DIR__ . '/Views/Responsive');
        } else {
            $view->addTemplateDir(__DIR__ . '/Views/Emotion');
            $view->extendsTemplate('frontend/checkout/index_darwinpricing.tpl');
        }
        $widgetUrl = $this->getApiUrl('/widget');
        $darwinPricingWidget = $this->loadAsynchronousJavascript($widgetUrl);
        $view->assign('darwinPricingWidget', $darwinPricingWidget);
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function readPluginInfo() {
        static $pluginInfo = null;
        if (!isset($pluginInfo)) {
            $pluginInfoJson = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'plugin.json');
            if (false === $pluginInfoJson) {
                throw new Exception('Plugin info not found');
            }
            $pluginInfo = json_decode($pluginInfoJson, true);
            if (!is_array($pluginInfo)) {
                throw new Exception('Plugin info invalid');
            }
        }
        return $pluginInfo;
    }

    protected function registerEvents() {
        $this->subscribeEvent('Enlight_Controller_Action_PostDispatchSecure_Frontend', 'onPostDispatch');
    }

    /**
     * @param string $sOrderNumber
     * @param string $visitorIp
     */
    protected function trackOrder($sOrderNumber, $visitorIp) {
        $url = $this->getApiUrl('/shopware/webhook-order', $visitorIp);
        $sOrder = $this->getOrder($sOrderNumber);
        if (null === $sOrder) {
            return;
        }
        $sOrder = $this->addUnitCosts($sOrder);
        $body = json_encode($sOrder);
        $this->webhook($url, $body);
    }

    /**
     * @param string $url
     * @param string $body
     */
    protected function webhook($url, $body) {
        $optionList = array(
            CURLOPT_POST => true,
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_POSTFIELDS => $body,
        );
        $this->executeOnShutdown(function() use($optionList) {
            $ch = curl_init();
            curl_setopt_array($ch, $optionList);
            curl_exec($ch);
            curl_close($ch);
        });
    }

}
