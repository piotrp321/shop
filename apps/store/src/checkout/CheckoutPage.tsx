"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useEffect, useState } from "react";
import {
  type AccountSession,
  type CartView,
  loadAccountSession,
  loadCart,
  type PlaceOrderInput,
  type PlaceOrderResult,
  placeOrder,
} from "@/api/client";
import { announceCartChange } from "@/cart/events";
import { SessionExpired } from "@/cart/messages";
import { formatPrice } from "@/catalog/format-price";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

type Loaded =
  | { status: "loading" }
  | { status: "error" }
  | { status: "unauthorized" }
  | { status: "ready"; cart: CartView; session: AccountSession };

type Form = { email: string; fullName: string; phone: string; street: string; postalCode: string; city: string };
type FieldName = keyof Form;

/** Why the last order was not placed; each is shown above the form (see the UI table of the checkout spec). */
type Failure =
  | { kind: "conflict"; detail: string; offersCart: boolean }
  | { kind: "not_found" }
  | { kind: "unauthorized" }
  | { kind: "message"; text: string };

const FIELDS: { name: FieldName; label: string; type: string; autoComplete: string }[] = [
  { name: "email", label: "E-mail", type: "email", autoComplete: "email" },
  { name: "fullName", label: "Imię i nazwisko", type: "text", autoComplete: "name" },
  { name: "phone", label: "Telefon", type: "tel", autoComplete: "tel" },
  { name: "street", label: "Ulica i numer", type: "text", autoComplete: "street-address" },
  { name: "postalCode", label: "Kod pocztowy", type: "text", autoComplete: "postal-code" },
  { name: "city", label: "Miejscowość", type: "text", autoComplete: "address-level2" },
];

const SUBMIT_FAILED = "Nie udało się złożyć zamówienia. Spróbuj ponownie.";
const CART_GONE = "Koszyk nie jest już dostępny.";
const FIELD_ID_PREFIX = "checkout-";
const FAILURE_ID = "checkout-failure";
const LOGIN_HREF = `/auth/login?returnTo=${encodeURIComponent("/checkout")}`;

const emptyForm: Form = { email: "", fullName: "", phone: "", street: "", postalCode: "", city: "" };

/** The key of one order attempt; sent again on a retry, so that a repeated request never places a second order. */
const newIdempotencyKey = () => crypto.randomUUID();

/**
 * The checkout: the summary of the cart from `/api/cart`, the contact and the address, and the order of the whole
 * cart through `/api/checkout`. Every amount comes from the API; the expected total is `totals.gross` of the summary
 * shown. A signed-in customer orders with the e-mail of the account.
 */
export function CheckoutPage() {
  const router = useRouter();
  const [loaded, setLoaded] = useState<Loaded>({ status: "loading" });
  const [form, setForm] = useState<Form>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<FieldName, string>>>({});
  const [failure, setFailure] = useState<Failure | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Where the focus goes after a refusal: the button was locked during the request, so the focus would be on the body.
  const [attention, setAttention] = useState<{ id: string; seq: number } | null>(null);
  // The key of one logical attempt. Made on entry and kept for a retry of an uncertain result (a network error, 403,
  // 5xx) and after a 409 that leaves the request as it was. A new one for a new attempt: after every change of the
  // form, after `IdempotencyKeyReused` and after `OrderTotalChanged` (the request now carries another total).
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  useEffect(() => {
    let active = true;
    void loadCheckout().then((result) => {
      if (!active) return;
      setLoaded(result);
      if (result.status === "ready") setForm(prefilled(result.session));
    });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (attention) document.getElementById(attention.id)?.focus();
  }, [attention]);

  function retry() {
    setLoaded({ status: "loading" });
    void loadCheckout().then((result) => {
      setLoaded(result);
      if (result.status === "ready") setForm(prefilled(result.session));
    });
  }

  function change(name: FieldName, value: string) {
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
    setIdempotencyKey(newIdempotencyKey());
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loaded.status !== "ready" || submitting) return;
    setSubmitting(true);
    setFailure(null);
    setFieldErrors({});
    const { email, ...contact } = form;
    const input: PlaceOrderInput = {
      ...(loaded.session === null ? { email } : {}),
      ...contact,
      expectedTotalGross: loaded.cart.totals.gross,
    };
    const result = await placeOrder(input, idempotencyKey);
    if (result.status === "ok") {
      announceCartChange(0);
      // The button stays locked: the confirmation page is on its way.
      router.push("/checkout/confirmation");
      return;
    }
    const target = await showFailure(result, loaded.session);
    setSubmitting(false);
    setAttention((current) => ({ id: target, seq: (current?.seq ?? 0) + 1 }));
  }

  /** Shows why the order was not placed; answers the id of the element that gets the focus. */
  async function showFailure(result: Exclude<PlaceOrderResult, { status: "ok" }>, session: AccountSession): Promise<string> {
    switch (result.status) {
      case "invalid": {
        const errors: Partial<Record<FieldName, string>> = {};
        const others: string[] = [];
        for (const violation of result.violations) {
          const name = FIELDS.find((candidate) => candidate.name === violation.propertyPath)?.name;
          if (name && (name !== "email" || session === null)) errors[name] ??= violation.title;
          else others.push(violation.title);
        }
        setFieldErrors(errors);
        if (others.length > 0 || Object.keys(errors).length === 0) {
          setFailure({ kind: "message", text: others.length > 0 ? others.join(" ") : result.detail || SUBMIT_FAILED });
        }
        const first = FIELDS.find((candidate) => errors[candidate.name]);
        return first ? `${FIELD_ID_PREFIX}${first.name}` : FAILURE_ID;
      }
      case "conflict": {
        // Told by the type of the problem only. Nothing is ordered again by itself.
        const detail = result.detail || SUBMIT_FAILED;
        if (result.reason === "key_reused") {
          // The key belongs to another request: the form is fine, the next click is a new attempt.
          setIdempotencyKey(newIdempotencyKey());
          setFailure({ kind: "conflict", detail, offersCart: false });
          return FAILURE_ID;
        }
        if (result.reason === "total_changed") {
          // Nothing was placed; the summary below is the new total, so the next click is a new request.
          setIdempotencyKey(newIdempotencyKey());
        }
        setFailure({ kind: "conflict", detail, offersCart: result.reason !== "total_changed" });
        // The cart as the API sees it now; an empty or unorderable one replaces the form.
        const reloaded = await loadCart();
        if (reloaded.status === "ok") {
          setLoaded({ status: "ready", cart: reloaded.data, session });
          announceCartChange(reloaded.data.totals.itemCount);
        }
        return FAILURE_ID;
      }
      case "not_found":
        // The only 404 of the checkout: the guest cart is gone (unknown, expired or merged).
        setFailure({ kind: "not_found" });
        return FAILURE_ID;
      case "unauthorized":
        setFailure({ kind: "unauthorized" });
        return FAILURE_ID;
      case "failed":
        setFailure({ kind: "message", text: SUBMIT_FAILED });
        return FAILURE_ID;
    }
  }

  // The message that replaces the form has its own link to the cart, so a conflict does not add a second one.
  const showsForm = loaded.status === "ready" && loaded.cart.lines.length > 0 && !loaded.cart.hasExcludedLines;

  return (
    <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
      <h1 className="text-2xl font-semibold">Kasa</h1>
      {loaded.status === "loading" ? <p className="text-muted-foreground">Wczytywanie…</p> : null}
      {loaded.status === "unauthorized" ? (
        <p role="alert">
          <SessionExpired returnTo="/checkout" />
        </p>
      ) : null}
      {loaded.status === "error" ? (
        <div role="alert" className="flex flex-col items-start gap-2">
          <p>Nie udało się wczytać kasy</p>
          <Button type="button" variant="outline" onClick={retry}>
            Spróbuj ponownie
          </Button>
        </div>
      ) : null}
      {failure ? <FailureMessage failure={failure} cartLinkShown={showsForm} /> : null}
      {loaded.status === "ready" ? (
        <CheckoutContent
          cart={loaded.cart}
          session={loaded.session}
          form={form}
          fieldErrors={fieldErrors}
          submitting={submitting}
          onChange={change}
          onSubmit={(event) => void submit(event)}
        />
      ) : null}
    </main>
  );
}

async function loadCheckout(): Promise<Loaded> {
  const [cart, session] = await Promise.all([loadCart(), loadAccountSession()]);
  if (cart.status === "unauthorized") return { status: "unauthorized" };
  if (cart.status !== "ok" || session.status !== "ok") return { status: "error" };
  return { status: "ready", cart: cart.data, session: session.session };
}

/** A customer's name comes from the account; an account without a name has its e-mail as the name, which is not one. */
function prefilled(session: AccountSession): Form {
  return session === null || session.name === session.email ? emptyForm : { ...emptyForm, fullName: session.name };
}

function FailureMessage({ failure, cartLinkShown }: { failure: Failure; cartLinkShown: boolean }) {
  return (
    <div id={FAILURE_ID} role="alert" tabIndex={-1} className="flex flex-col items-start gap-1 text-destructive">
      {failure.kind === "unauthorized" ? (
        <p>
          <SessionExpired returnTo="/checkout" />
        </p>
      ) : failure.kind === "message" ? (
        <p>{failure.text}</p>
      ) : (
        <>
          <p>{failure.kind === "conflict" ? failure.detail : CART_GONE}</p>
          {failure.kind === "not_found" ? (
            <Link href="/" className="text-sm text-foreground underline underline-offset-4">
              Wróć do sklepu
            </Link>
          ) : failure.offersCart && cartLinkShown ? (
            <Link href="/cart" className="text-sm text-foreground underline underline-offset-4">
              Wróć do koszyka
            </Link>
          ) : null}
        </>
      )}
    </div>
  );
}

function CheckoutContent({
  cart,
  session,
  form,
  fieldErrors,
  submitting,
  onChange,
  onSubmit,
}: {
  cart: CartView;
  session: AccountSession;
  form: Form;
  fieldErrors: Partial<Record<FieldName, string>>;
  submitting: boolean;
  onChange: (name: FieldName, value: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  if (cart.lines.length === 0 || cart.hasExcludedLines) {
    return (
      <div className="flex flex-col items-start gap-2">
        <p>
          {cart.lines.length === 0
            ? "Twój koszyk jest pusty."
            : "Część pozycji w koszyku nie może zostać kupiona. Usuń albo popraw oznaczone pozycje."}
        </p>
        <Link href="/cart" className="text-sm underline underline-offset-4">
          Przejdź do koszyka
        </Link>
      </div>
    );
  }

  return (
    <>
      <OrderSummary cart={cart} />
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
        {session === null ? (
          <p className="text-sm text-muted-foreground">
            Masz konto?{" "}
            <a href={LOGIN_HREF} className="text-foreground underline underline-offset-4">
              Zaloguj się
            </a>
          </p>
        ) : (
          <p className="text-sm">
            Potwierdzenie wyślemy na adres <span data-testid="account-email">{session.email}</span>
          </p>
        )}
        {FIELDS.filter((field) => field.name !== "email" || session === null).map((field) => (
          <FormField key={field.name} {...field} value={form[field.name]} error={fieldErrors[field.name]} onChange={onChange} />
        ))}
        <Button type="submit" className="self-end" disabled={submitting}>
          Zamawiam z obowiązkiem zapłaty
        </Button>
      </form>
    </>
  );
}

function FormField({
  name,
  label,
  type,
  autoComplete,
  value,
  error,
  onChange,
}: {
  name: FieldName;
  label: string;
  type: string;
  autoComplete: string;
  value: string;
  error?: string;
  onChange: (name: FieldName, value: string) => void;
}) {
  const id = `${FIELD_ID_PREFIX}${name}`;
  const errorId = `${id}-error`;
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      <Input
        id={id}
        name={name}
        type={type}
        autoComplete={autoComplete}
        value={value}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        onChange={(event) => onChange(name, event.target.value)}
      />
      {error ? (
        <p id={errorId} className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}

/** Lines and total exactly as the API gave them; nothing is computed here. */
function OrderSummary({ cart }: { cart: CartView }) {
  return (
    <section aria-label="Podsumowanie zamówienia" className="flex flex-col gap-2">
      <ul className="flex flex-col divide-y divide-border">
        {cart.lines.map((line) => (
          <li key={line.variantId} className="flex justify-between gap-4 py-2">
            <div className="flex flex-col">
              <span className="text-sm font-medium">{line.product?.name}</span>
              {line.product?.variantLabel ? (
                <span data-testid="checkout-line-variant" className="text-sm text-muted-foreground">
                  {line.product.variantLabel}
                </span>
              ) : null}
              <span data-testid="checkout-line-quantity" className="text-sm text-muted-foreground">
                {line.quantity} szt.
              </span>
            </div>
            {line.lineTotal ? (
              <span data-testid="checkout-line-total" className="text-sm font-semibold">
                {formatPrice(line.lineTotal.gross, line.lineTotal.currency)}
              </span>
            ) : null}
          </li>
        ))}
      </ul>
      <p className="flex justify-between border-t border-border pt-2 font-semibold">
        <span>Do zapłaty (brutto)</span>
        <span data-testid="checkout-total">{formatPrice(cart.totals.gross, cart.totals.currency)}</span>
      </p>
    </section>
  );
}
