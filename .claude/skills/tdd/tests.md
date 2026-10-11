# Good and Bad Tests

## Good Tests

**Integration-style**: Test through real interfaces, not mocks of internal parts.

```typescript
// GOOD: Tests observable behavior
test('user can checkout with valid cart', async () => {
    const cart = createCart();
    cart.add(product);
    const result = await checkout(cart, paymentMethod);
    expect(result.status).toBe('confirmed');
});
```

Characteristics:

- Tests behavior users/callers care about
- Uses public API only
- Survives internal refactors
- Describes WHAT, not HOW
- One logical assertion per test

## Bad Tests

**Implementation-detail tests**: Coupled to internal structure.

```typescript
// BAD: Tests implementation details
test('checkout calls paymentService.process', async () => {
    const mockPayment = jest.mock(paymentService);
    await checkout(cart, payment);
    expect(mockPayment.process).toHaveBeenCalledWith(cart.total);
});
```

Red flags:

- Mocking internal collaborators
- Testing private methods
- Asserting on call counts/order
- Test breaks when refactoring without behavior change
- Test name describes HOW not WHAT
- Verifying through external means instead of interface

```typescript
// BAD: Bypasses interface to verify
test('createUser saves to database', async () => {
    await createUser({ name: 'Alice' });
    const row = await db.query('SELECT * FROM users WHERE name = ?', ['Alice']);
    expect(row).toBeDefined();
});

// GOOD: Verifies through interface
test('createUser makes user retrievable', async () => {
    const user = await createUser({ name: 'Alice' });
    const retrieved = await getUser(user.id);
    expect(retrieved.name).toBe('Alice');
});
```

**Tautological tests**: Expected value restates the implementation, so the test passes by construction.

```typescript
// BAD: Expected value is recomputed the way the code computes it
test('calculateTotal sums line items', () => {
    const items = [{ price: 10 }, { price: 5 }];
    const expected = items.reduce((sum, i) => sum + i.price, 0);
    expect(calculateTotal(items)).toBe(expected);
});

// GOOD: Expected value is an independent, known literal
test('calculateTotal sums line items', () => {
    expect(calculateTotal([{ price: 10 }, { price: 5 }])).toBe(15);
});
```

## In this repo (Pest, illustrative names)

The same rules, in the project's own test style. Method and factory names below are illustrative; use the real ones from the code you're touching.

```php
// BAD: tautological — the expected value is computed the way the code computes it.
it('computes the energy of a preparation', function () {
    $lines = [[100, 52.0], [50, 389.0]]; // grams, kcal per 100 g
    $expected = array_sum(array_map(fn ($l) => $l[0] * $l[1] / 100, $lines));
    expect($preparation->energyKcal())->toBe($expected);
});

// GOOD: an independent, hand-computed literal, with the arithmetic shown.
it('computes the energy of a preparation from its lines', function () {
    // 100 g apple at 52 kcal/100 g = 52.0 ; 50 g oats at 389 kcal/100 g = 194.5 ; total 246.5
    expect($preparation->energyKcal())->toBe(246.5);
});

// GOOD: an HTTP seam — behaviour a user can observe, including the refusal paths.
it('refuses to show a preparation owned by another user', function () {
    $this->actingAs($otherUser)
        ->get(route('preparations.show', $preparation))
        ->assertForbidden();
});
```
