<?php

class ShipStream_Magento1_ShippingMethodConfig implements Plugin_Custom_Interface
{
    /** @var Plugin_Abstract */
    protected $_plugin;

    /**
     * @param Plugin_Abstract $plugin
     * @return string
     */
    public function render(Plugin_Abstract $plugin)
    {
        $this->_plugin = $plugin;
        $helper = Mage::helper('plugin');
        $css = <<<CSS
.magento1-component { min-width: 315px; border: 1px solid #cbd3d4; margin: 0 1px; }
.magento1-component > div:nth-child(odd) { background-color: #f7f7f7; }
.magento1-component-footer { background: #ddd !important; text-align: right; }
.magento1-component-footer button { float: inherit; margin: 4px; }
.magento1-container { display: flex; align-items: center; font-weight: normal;  }
.magento1-container span.handle { cursor: pointer; }
.magento1-container input, .magento1-container select { min-height: 17px; padding: 4px !important; }
.magento1-container:nth-child(n+2) { border-top: 1px solid #cbd3d4; }
.magento1-container > div { padding: 4px 10px; }
.magento1-container > div:nth-child(1) { display: flex; justify-content: center; align-items: center; }
.magento1-container > div:nth-child(2) { flex-grow: 2; }
.magento1-container > div:nth-child(3) { padding: 4px 0; }
.magento1-config > div:nth-child(2) { padding-top: 5px; }
.magento1-config-row { display: flex; }
.magento1-config-row label { display: block; padding-top: 5px; }
.magento1-config-row .config-pattern { padding-left: 8px; }
.magento1-config-row .config-pattern input.input-text { width: 20em; }
.magento1-config-row > div { padding: 0 2px; }
#no-method-translations { margin: 0 1px; border-bottom: 0; text-align: center !important; padding: 4px; background-color: #f7f7f7; }
#no-method-translations:hover { background-color:#e7e7e7; }
#no-method-translations label { font-weight: normal; }
CSS;

        $template = <<<HTML
<template id="config-row-template" v-bind:config="config">
    <div class="magento1-container">
        <div>
            <span class="handle">|||</span>
        </div>
        <div class="magento1-config">
            <div class="magento1-config-row">
                <div class="nobr">
                    <p><label>{$helper->__('If Magento')}</label></p>
                </div>
                <div>
                    <div class="field-row">
                        <select v-model="config.field"
                            :id = "'config-'+config.id+'-field'"
                            class="select validate-select required-entry"
                            style="width: auto"
                        >
                            <option v-for="option in fieldOptions" v-bind:value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <div>
                    <div class="field-row">
                        <select v-model="config.operator"
                            :id = "'config-'+config.id+'-operator'"
                            class="select validate-select required-entry"
                            style="width: auto"
                        >
                            <option v-for="option in operatorOptions" v-bind:value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="nobr">
                    <label v-if="this.config.operator === '=~'">{$helper->__('the pattern')}</label>
                    <label v-else>{$helper->__('the value')}</label>
                </div>
                <div class="config-pattern">
                    <div class="field-row nobr">
                        <template v-if="this.config.operator === '=~'">/^</template><input v-model="config.pattern"
                            :id = "'config-'+config.id+'-pattern'"
                            class="text input-text required-entry code"
                            :class="[this.config.operator === '=~' ? 'validate-regexp' : '']"
                        ><template v-if="this.config.operator === '=~'">$/i</template>
                    </div>
                </div>
            </div>
            <div class="magento1-config-row">
                <div class="nobr">
                    <label>{$helper->__('then use')}</label>
                </div>
                <div>
                    <div class="field-row">
                        <select v-model="config.shipping_method"
                            :id = "'config-'+config.id+'-shipping-method'"
                            class="select validate-select required-entry"
                        >
                            <option v-for="option in shippingMethodOptions" v-bind:value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div>
            <mwe-button class="delete" @click.stop="remove(config.id)">{$helper->__('Delete')}</mwe-button>
        </div>
    </div>
</template>
<template id="shipping-method-config-template">
    <fieldset>
        <div v-if="configs.length === 0" id="no-method-translations">
            <label>{$helper->__('No Method Translations')}</label>
        </div>
        <draggable v-model="configs" handle=".handle" tag="div" class="magento1-component">
            <div v-for="config in configs" :key="config.id">
                <config-row :config="config"
                            :field-options="fieldOptions"
                            :operator-options="operatorOptions"
                            :shipping-method-options="shippingMethodOptions"
                            v-on:remove="remove"
                ></config-row>
            </div>
            <div class="magento1-component-footer">
                <mwe-button class="add" @click="add()">{$helper->__('Add Method Translation')}</mwe-button>
            </div>
        </draggable>
    </fieldset>
</template>
HTML;

        $component = <<<JS
(function(window, Vue) {
    Vue.component('config-row', {
        props: {
            config: {
                types: [Array],
                required: true
            },
            fieldOptions: {
                types: [Array],
                required: true
            },
            operatorOptions: {
                type: [Array],
                required: true
            },
            shippingMethodOptions: {
                type: [Array],
                required: true
            }
        },
        data: function () {
            return {}
        },
        methods: {
            // Remove a config
            remove: function(id) {
                this.\$emit('remove', id);
            }
        },
        template: '#config-row-template'
    });
    Vue.component('shipping-method-config', {
        props: {
            fieldOptions: {
                types: [Array],
                required: true
            },
            operatorOptions: {
                type: [Array],
                required: true
            },
            shippingMethodOptions: {
                type: [Array],
                required: true
            },
            value: {
                type: [Array, String],
                required: true
            }
        },
        components: {
            draggable: window['vuedraggable']
        },
        data: function() {
            return  {
                idCounter: 1,
                configs: []
            }
        },
        watch: {
            configs: {
                handler(val) {
                    if ( ! val.length) {
                        this.\$emit('input', '');
                        return;
                    }
                    // Emit updates back to the form element wrapper
                    this.\$emit('input', val.map(config => {
                        let clonedConfig = JSON.parse(JSON.stringify(config));
                        delete clonedConfig.id;
                        return clonedConfig;
                    }))
                },
                deep: true
            }
        },
        created: function() {
            if ( ! Array.isArray(this.value)) {
                return;
            }
            // Initialize local config objects
            this.value.forEach((config) => {
                let clonedConfig = JSON.parse(JSON.stringify(config));
                clonedConfig.id = this.idCounter++;
                this.configs.push(clonedConfig);
            })
        },
        methods: {
            // Remove a config
            remove: function(id) {
                if (confirm('{$helper->__('Are you sure?')}')) {
                    let index = this.configs.findIndex((config) => config.id === id);
                    if (index !== -1) {
                        this.configs.splice(index, 1)
                    }
                }
            },
            // Add a config
            add: function () {
                let newConfig = this.getEmptyConfig();
                newConfig.id = this.idCounter++;
                this.configs.push(newConfig);
            },
            getEmptyConfig: function() {
                return {
                    shipping_method: null,
                    field: null,
                    operator: null,
                    pattern: null
                }
            }
        },
        template: '#shipping-method-config-template'
    });
})(window, Vue);
JS;

        return <<<HTML
<style>
$css
</style>
$template
<script type="text/javascript">
$component
</script>
{$this->initVue()}
HTML;
    }

    /**
     * @return string
     */
    public function initVue()
    {
        $vue = new Varien_Data_Form_Element_Vue();
        $vue->setForm(new Varien_Data_Form([
            'field_name_suffix' => 'config_data',
        ]));
        $vue->addData([
            'html_id' => 'shipping_method_config_vue',
            'props' => [
                ':field-options' => $this->getFieldOptions(),
                ':operator-options' => $this->getOperatorOptions(),
                ':shipping-method-options' => $this->getShippingMethodOptions(),
                ':value' => NULL,
            ],
            'component_tag' => 'shipping-method-config',
            'name' => 'shipping_method_config',
            'value' => $this->getConfigs(),
        ]);

        return $vue->getElementHtml();
    }

    /**
     * @return string
     */
    public function getFieldOptions()
    {
        static $options;
        if (is_null($options)) {
            $options = [
                ['value' => '', 'label' => ''],
                ['value' => 'shipping_method', 'label' => Mage::helper('plugin')->__('Shipping Method')],
                ['value' => 'shipping_description', 'label' => Mage::helper('plugin')->__('Shipping Description')],
            ];
        }

        return json_encode($options);
    }

    /**
     * @return string
     */
    public function getOperatorOptions()
    {
        static $options;
        if (is_null($options)) {
            $options = [
                ['value' => '',   'label' => ''],
                ['value' => '=',  'label' => Mage::helper('plugin')->__('equals')],
                ['value' => '!=', 'label' => Mage::helper('plugin')->__('does not equal')],
                ['value' => '=~', 'label' => Mage::helper('plugin')->__('matches')],
            ];
        }

        return json_encode($options);
    }

    /**
     * @return string
     */
    public function getShippingMethodOptions()
    {
        $shippingMethods = Mage::getSingleton('shipping/config')->getAllowedMethodsForActiveCarriersByWebsite(
            $this->getPlugin()->getWebsiteId(),
            TRUE
        );
        $options = [['' => '']];
        foreach ($shippingMethods as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return json_encode($options);
    }

    /**
     * @return string
     */
    public function getConfigs()
    {
        $config = $this->getPlugin()->getConfig('shipping_method_config');
        json_decode($config);

        return json_last_error() === JSON_ERROR_NONE ? $config : '[]';
    }

    /**
     * @return Plugin_Abstract
     * @throws Exception
     */
    public function getPlugin()
    {
        if ( ! $this->_plugin) {
            throw new Exception('Plugin is not set.');
        }

        return $this->_plugin;
    }
}
