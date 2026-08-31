# Universal Commerce for Magento 2 (Coming Soon)

This module makes your store compatible with AI-powered shopping experiences using the [Universal Commerce Protocol (UCP)](https://developers.google.com/merchant/ucp).

**Currently under development**. More details will be provided soon!

## Features

- [x] UCP Checkout Capability (Quote creation, updates, cancellation)
- [ ] UCP Order Capability
- [ ] UCP Identity Linking Capability
- [ ] Unit and integration tests

## What is UCP?

The Universal Commerce Protocol (UCP) is an open-source standard designed to power the next generation of agentic commerce. By establishing a common language and functional primitives, UCP enables seamless commerce journeys between consumer surfaces, businesses, and payment providers. It is built to work with existing retail infrastructure, and is compatible with Agent Payments Protocol (AP2) to provide secure agentic payments support. It also provides businesses flexible ways to integrate via APIs, Agent2Agent (A2A), and the Model Context Protocol (MCP).

UCP is developed by Google in collaboration with industry leaders including Shopify, Etsy, Wayfair, Target, and Walmart endorsed by over 20 global partners across the ecosystem like Adyen, American Express, Best Buy, Flipkart, Macy's Inc, Mastercard, Stripe, The Home Depot, Visa, Zalando and many more.

## Conformance testing

The UCP conformance suite needs a way to move an order to shipped so it can check the order event that
follows. That is not part of UCP: the suite hardcodes the path `POST /testing/simulate-shipping/{order_id}`
and a `Simulation-Secret` header, copying the reference sample server.

The endpoint stays switched off until a secret is set in `app/etc/env.php`:

```php
'universal_commerce' => [
    'simulation_secret' => 'a-long-random-value'
],
```

Remove the key to switch it off again. With no secret set the endpoint answers `404` as though it were
never routed, and it only ever ships orders that UCP placed itself — never the storefront's.

Do not set this on a production store unless you are running the suite against it.

## Contributing

Found a bug, have a feature suggestion or just want to help in general? Contributions are very welcome! Check out the list of active issues or submit one yourself.

---
![Magebit](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)

Magebit - Full-service e-commerce agency
[magebit.com](https://magebit.com)
