ShipStream Plugin for OpenMage / Magento 1
====

This project is a plugin for ShipStream which is designed to communicate with the [ShipStream OpenMage Sync Extension](https://github.com/ShipStream/openmage-sync)
and operate within the [ShipStream Merchant Plugin Middleware](https://github.com/ShipStream/middleware) environment.

Installation
----

1. Follow the instruction to [install the middleware environment]()
2. Run `$ bin/modman init` to initialize the root directory
3. Run `$ bin/modman clone https://github.com/shipstream/plugin-openmage.git` to clone this project

Setup
----

This plugin requires that you create an API key in the Magento store which you wish to connect with and then provide the API key credentials to the
plugin configuration.

**Example local.xml file for the middleware environment:**

```xml
<?xml version="1.0"?>
<config>
    <default>
        <middleware>
            ...
        </middleware>
        <plugin>
            <ShipStream_Magento1>
                <api_url>###</api_url>
                <api_login>###</api_login>
                <api_password>###</api_password>
                <auto_fulfill>###</auto_fulfill>
                <sync_orders_since>###</sync_orders_since>
            </ShipStream_Magento1>
        </plugin>
    </default>
</config>
```

**Notes**

1. `<auto_fulfill>` The order status for filtering orders which are ready for fulfillment; default value: `ready_to_ship`.
1. `<sync_orders_since>` The order last update date which are ready for syncing; valid format: `YYYY-MM-DD`.
