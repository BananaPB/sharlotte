# When to Mock

Mock at **system boundaries** only:

- External APIs (payment, email, etc.)
- Databases (sometimes - prefer test DB)
- Time/randomness
- File system (sometimes)

Don't mock:

- Your own classes/modules
- Internal collaborators
- Anything you control

## Designing for Mockability

At system boundaries, design interfaces that are easy to mock:

**1. Use dependency injection**

Pass external dependencies in rather than creating them internally:

```typescript
// Easy to mock
function processPayment(order, paymentClient) {
    return paymentClient.charge(order.total);
}

// Hard to mock
function processPayment(order) {
    const client = new StripeClient(process.env.STRIPE_KEY);
    return client.charge(order.total);
}
```

**2. Prefer SDK-style interfaces over generic fetchers**

Create specific functions for each external operation instead of one generic function with conditional logic:

```typescript
// GOOD: Each function is independently mockable
const api = {
    getUser: (id) => fetch(`/users/${id}`),
    getOrders: (userId) => fetch(`/users/${userId}/orders`),
    createOrder: (data) => fetch('/orders', { method: 'POST', body: data }),
};

// BAD: Mocking requires conditional logic inside the mock
const api = {
    fetch: (endpoint, options) => fetch(endpoint, options),
};
```

The SDK approach means:

- Each mock returns one specific shape
- No conditional logic in test setup
- Easier to see which endpoints a test exercises
- Type safety per endpoint

## In this repo

- **Never mock the database.** Tests run against the real Postgres `testing` database (`docs/decisions.md` entry 5: SQLite and mocks both hide the decimal-math behaviour the domain depends on). Use factories and `RefreshDatabase`.
- **Never mock Eloquent models or your own services.** If a test seems to need it, the seam is in the wrong place.
- Mock only real system boundaries: outgoing HTTP (`Http::fake()`), mail (`Mail::fake()`), queues (`Queue::fake()`), time (`$this->travelTo()`).
