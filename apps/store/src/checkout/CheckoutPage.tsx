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
import { ScreenMain, ScreenTitle } from "@/components/ScreenLayout";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { navigateTo } from "./navigate";
import type { PaymentProvider } from "./payment-api";

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

const CHOOSE_PROVIDER = "Wybierz sposób płatności.";
const PROVIDER_ID_PREFIX = "checkout-provider-";
const PROVIDER_ERROR_ID = "checkout-provider-error";
const SUBMIT_FAILED = "Nie udało się złożyć zamówienia. Spróbuj ponownie.";
const RATE_LIMITED = "Zbyt wiele prób złożenia zamówienia. Spróbuj ponownie za kilka minut.";
const CART_GONE = "Koszyk nie jest już dostępny.";
const FIELD_ID_PREFIX = "checkout-";
const FAILURE_ID = "checkout-failure";
const LOGIN_HREF = `/auth/login?returnTo=${encodeURIComponent("/checkout")}`;

const CODE_INPUT_ID = "checkout-discount-code";
const CODE_ERROR_ID = "checkout-discount-code-error";
const CODE_REMOVE_ID = "checkout-discount-remove";
const CODE_MAX_LENGTH = 64;
const CODE_BLANK = "Wpisz kod rabatowy.";
const CODE_NOT_CHECKED = "Nie udało się sprawdzić kodu. Spróbuj ponownie.";
const CODE_NOT_REMOVED = "Nie udało się odświeżyć podsumowania. Spróbuj ponownie.";

/** What the summary of the cart says about the code asked for: applied (the code), or the text of its rejection. */
type Preview =
  | { status: "ok"; cart: CartView; appliedCode: string | null; codeError: string | null }
  | { status: "unauthorized" }
  | { status: "failed" };

const emptyForm: Form = { email: "", fullName: "", phone: "", street: "", postalCode: "", city: "" };

/** The key of one order attempt; sent again on a retry, so that a repeated request never places a second order. */
const newIdempotencyKey = () => crypto.randomUUID();

/**
 * The checkout: the summary of the cart from `/api/cart`, the contact and the address, the choice of the payment
 * provider and the order of the whole cart through `/api/checkout`, which also starts the payment: the browser then
 * goes to the page of the provider, or to the confirmation page when the payment could not be started. Every amount
 * comes from the API; the expected total is `totals.gross` of the summary shown. A signed-in customer orders with the
 * e-mail of the account.
 *
 * `providers` is the list of the API read by the server (null when it was not available) and `pendingOrderNumber` the
 * number of the order of the `store_order_confirmation` cookie, if there is one: after a "back" from the provider the
 * cart is empty, and the page offers to pay that order.
 */
export function CheckoutPage({ providers, pendingOrderNumber }: { providers: PaymentProvider[] | null; pendingOrderNumber: string | null }) {
  const router = useRouter();
  const [loaded, setLoaded] = useState<Loaded>({ status: "loading" });
  const [form, setForm] = useState<Form>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<FieldName, string>>>({});
  const [failure, setFailure] = useState<Failure | null>(null);
  // No default: a customer chooses how to pay. The key of the order does not depend on it (the provider is not part of
  // the order, only of the payment started after it).
  const [provider, setProvider] = useState<string | null>(null);
  const [providerError, setProviderError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // The discount code lives in this component only (D3): a reload of the page starts without one. `appliedCode` is the
  // code the summary of `loaded` was priced with; `codeInput` is what the customer types.
  const [codeInput, setCodeInput] = useState("");
  const [appliedCode, setAppliedCode] = useState<string | null>(null);
  const [codeError, setCodeError] = useState<string | null>(null);
  const [codeStatus, setCodeStatus] = useState("");
  const [codeBusy, setCodeBusy] = useState(false);
  // Where the focus goes after a refusal: the button was locked during the request, so the focus would be on the body.
  const [attention, setAttention] = useState<{ id: string; seq: number } | null>(null);
  // The key of one logical attempt. Made on entry and kept for a retry of an uncertain result (a network error, 403,
  // 5xx), after a 429 of the rate limit and after a 409 that leaves the request as it was. A new one for a new attempt: after every change of the
  // form, after `IdempotencyKeyReused` and after `OrderTotalChanged` (the request now carries another total), and
  // when a code is applied or removed or refused (the request carries another code and total).
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

  /** Reads the summary again with `code` and shows what the API says of it; answers how the read ended. */
  async function reloadPreview(code: string | null, session: AccountSession): Promise<Preview["status"]> {
    const preview = await loadPreview(code);
    if (preview.status === "unauthorized") {
      setFailure({ kind: "unauthorized" });
      return "unauthorized";
    }
    if (preview.status === "failed") return "failed";
    setLoaded({ status: "ready", cart: preview.cart, session });
    setAppliedCode(preview.appliedCode);
    setCodeError(preview.codeError);
    announceCartChange(preview.cart.totals.itemCount);
    return "ok";
  }

  async function applyCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loaded.status !== "ready" || codeBusy || submitting) return;
    const code = codeInput.trim();
    if (code === "") {
      setCodeError(CODE_BLANK);
      setAttention((current) => ({ id: CODE_INPUT_ID, seq: (current?.seq ?? 0) + 1 }));
      return;
    }
    setCodeBusy(true);
    setCodeError(null);
    const preview = await loadPreview(code);
    setCodeBusy(false);
    let target = CODE_INPUT_ID;
    if (preview.status === "ok") {
      setLoaded({ status: "ready", cart: preview.cart, session: loaded.session });
      setAppliedCode(preview.appliedCode);
      setCodeError(preview.codeError);
      if (preview.appliedCode !== null) {
        // The request now carries a code and another total; a rejected code leaves it as it was.
        setIdempotencyKey(newIdempotencyKey());
        setCodeStatus(`Zastosowano kod ${preview.cart.discount?.code ?? preview.appliedCode}.`);
        target = CODE_REMOVE_ID;
      }
    } else if (preview.status === "unauthorized") {
      setFailure({ kind: "unauthorized" });
      target = FAILURE_ID;
    } else {
      setCodeError(CODE_NOT_CHECKED);
    }
    setAttention((current) => ({ id: target, seq: (current?.seq ?? 0) + 1 }));
  }

  async function removeCode() {
    if (loaded.status !== "ready" || codeBusy || submitting) return;
    setCodeBusy(true);
    setCodeError(null);
    const preview = await loadPreview(null);
    setCodeBusy(false);
    let target = CODE_REMOVE_ID;
    if (preview.status === "ok") {
      setLoaded({ status: "ready", cart: preview.cart, session: loaded.session });
      setAppliedCode(null);
      setCodeInput("");
      setCodeStatus("Usunięto kod rabatowy.");
      setIdempotencyKey(newIdempotencyKey());
      target = CODE_INPUT_ID;
    } else if (preview.status === "unauthorized") {
      setFailure({ kind: "unauthorized" });
      target = FAILURE_ID;
    } else {
      setCodeError(CODE_NOT_REMOVED);
    }
    setAttention((current) => ({ id: target, seq: (current?.seq ?? 0) + 1 }));
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loaded.status !== "ready" || submitting || codeBusy || providers === null || providers.length === 0) return;
    if (provider === null) {
      setProviderError(CHOOSE_PROVIDER);
      setAttention((current) => ({ id: `${PROVIDER_ID_PREFIX}${providers[0].code}`, seq: (current?.seq ?? 0) + 1 }));
      return;
    }
    setSubmitting(true);
    setProviderError(null);
    setFailure(null);
    setFieldErrors({});
    const { email, ...contact } = form;
    const input: PlaceOrderInput = {
      ...(loaded.session === null ? { email } : {}),
      ...contact,
      expectedTotalGross: loaded.cart.totals.gross,
      provider,
      ...(appliedCode === null ? {} : { discountCode: appliedCode }),
    };
    const result = await placeOrder(input, idempotencyKey);
    if (result.status === "ok") {
      announceCartChange(0);
      // The button stays locked: the page of the provider, or the confirmation page, is on its way.
      if (result.redirectUrl === null) {
        router.push("/checkout/confirmation");
      } else {
        navigateTo(result.redirectUrl);
      }
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
        let providerMessage: string | null = null;
        for (const violation of result.violations) {
          if (violation.propertyPath === "provider") {
            providerMessage ??= violation.title;
            continue;
          }
          const name = FIELDS.find((candidate) => candidate.name === violation.propertyPath)?.name;
          if (name && (name !== "email" || session === null)) errors[name] ??= violation.title;
          else others.push(violation.title);
        }
        setFieldErrors(errors);
        setProviderError(providerMessage);
        if (others.length > 0 || (Object.keys(errors).length === 0 && providerMessage === null)) {
          setFailure({ kind: "message", text: others.length > 0 ? others.join(" ") : result.detail || SUBMIT_FAILED });
        }
        const first = FIELDS.find((candidate) => errors[candidate.name]);
        if (first) return `${FIELD_ID_PREFIX}${first.name}`;
        return providerMessage !== null && providers?.length ? `${PROVIDER_ID_PREFIX}${providers[0].code}` : FAILURE_ID;
      }
      case "conflict": {
        // Told by the type of the problem only. Nothing is ordered again by itself.
        const detail = result.detail || SUBMIT_FAILED;
        if (result.reason === "discount_code_rejected") {
          // The code does not work any more. It comes off, the summary is read without it, and nothing is ordered:
          // the customer sees the new total and clicks again. When the summary cannot be read, the screen stays as it
          // was (the code applied, with its line and total) and the customer can take the code off by hand.
          setIdempotencyKey(newIdempotencyKey());
          const read = await reloadPreview(null, session);
          if (read === "ok") {
            setCodeError(detail);
            return CODE_INPUT_ID;
          }
          if (read === "unauthorized") return FAILURE_ID;
          setCodeError(`${detail} ${CODE_NOT_REMOVED}`);
          return CODE_REMOVE_ID;
        }
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
        // The cart as the API sees it now, with the same code; an empty or unorderable one replaces the form.
        await reloadPreview(appliedCode, session);
        return FAILURE_ID;
      }
      case "not_found":
        // The only 404 of the checkout: the guest cart is gone (unknown, expired or merged).
        setFailure({ kind: "not_found" });
        return FAILURE_ID;
      case "unauthorized":
        setFailure({ kind: "unauthorized" });
        return FAILURE_ID;
      case "rate_limited":
        // Nothing was placed: the form stays, and so does the key for the retry in a few minutes.
        setFailure({ kind: "message", text: RATE_LIMITED });
        return FAILURE_ID;
      case "failed":
        setFailure({ kind: "message", text: SUBMIT_FAILED });
        return FAILURE_ID;
    }
  }

  // The message that replaces the form has its own link to the cart, so a conflict does not add a second one.
  const showsForm = loaded.status === "ready" && loaded.cart.lines.length > 0 && !loaded.cart.hasExcludedLines;

  return (
    <ScreenMain>
      <ScreenTitle>Kasa</ScreenTitle>
      {loaded.status === "loading" ? <p className="text-muted-foreground">Wczytywanie…</p> : null}
      {loaded.status === "unauthorized" ? (
        <Alert variant="destructive">
          <AlertDescription>
            <SessionExpired returnTo="/checkout" />
          </AlertDescription>
        </Alert>
      ) : null}
      {loaded.status === "error" ? (
        <Alert variant="destructive" className="justify-items-start gap-3">
          <AlertDescription>Nie udało się wczytać kasy</AlertDescription>
          <Button type="button" variant="outline" onClick={retry}>
            Spróbuj ponownie
          </Button>
        </Alert>
      ) : null}
      {failure ? <FailureMessage failure={failure} cartLinkShown={showsForm} /> : null}
      {loaded.status === "ready" ? (
        <CheckoutContent
          cart={loaded.cart}
          session={loaded.session}
          form={form}
          fieldErrors={fieldErrors}
          providers={providers}
          provider={provider}
          providerError={providerError}
          pendingOrderNumber={pendingOrderNumber}
          submitting={submitting}
          code={{ input: codeInput, applied: appliedCode !== null, error: codeError, status: codeStatus, busy: codeBusy }}
          onCodeInput={(value) => {
            setCodeInput(value);
            setCodeError(null);
          }}
          onApplyCode={(event) => void applyCode(event)}
          onRemoveCode={() => void removeCode()}
          onChange={change}
          onProviderChange={(code) => {
            setProvider(code);
            setProviderError(null);
          }}
          onSubmit={(event) => void submit(event)}
        />
      ) : null}
    </ScreenMain>
  );
}

/**
 * The summary with the preview of `code` (none: the plain summary). A code the API refuses outright (400) leaves the
 * plain summary and the API's text for the field; a rejected one gives the summary without a discount and its `detail`.
 * Nothing is computed here: whether a code applies, and what it takes off, is the API's.
 */
async function loadPreview(code: string | null): Promise<Preview> {
  const result = await loadCart(...(code === null ? [] : [code]));
  if (result.status === "unauthorized") return { status: "unauthorized" };
  if (result.status === "invalid") {
    const plain = await loadCart();
    if (plain.status === "unauthorized") return { status: "unauthorized" };
    return plain.status === "ok"
      ? { status: "ok", cart: plain.data, appliedCode: null, codeError: result.detail ?? CODE_NOT_CHECKED }
      : { status: "failed" };
  }
  if (result.status !== "ok") return { status: "failed" };
  if (code === null) return { status: "ok", cart: result.data, appliedCode: null, codeError: null };
  const discount = result.data.discount;
  if (discount?.status === "applied") return { status: "ok", cart: result.data, appliedCode: code, codeError: null };
  return { status: "ok", cart: result.data, appliedCode: null, codeError: discount?.status === "rejected" ? discount.detail : CODE_BLANK };
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
    <Alert id={FAILURE_ID} variant="destructive" tabIndex={-1}>
      <AlertDescription>
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
              <p>
                <Link href="/">Wróć do sklepu</Link>
              </p>
            ) : failure.offersCart && cartLinkShown ? (
              <p>
                <Link href="/cart">Wróć do koszyka</Link>
              </p>
            ) : null}
          </>
        )}
      </AlertDescription>
    </Alert>
  );
}

function CheckoutContent({
  cart,
  session,
  form,
  fieldErrors,
  providers,
  provider,
  providerError,
  pendingOrderNumber,
  submitting,
  code,
  onCodeInput,
  onApplyCode,
  onRemoveCode,
  onChange,
  onProviderChange,
  onSubmit,
}: {
  cart: CartView;
  session: AccountSession;
  form: Form;
  fieldErrors: Partial<Record<FieldName, string>>;
  providers: PaymentProvider[] | null;
  provider: string | null;
  providerError: string | null;
  pendingOrderNumber: string | null;
  submitting: boolean;
  code: CodeState;
  onCodeInput: (value: string) => void;
  onApplyCode: (event: FormEvent<HTMLFormElement>) => void;
  onRemoveCode: () => void;
  onChange: (name: FieldName, value: string) => void;
  onProviderChange: (code: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  if (cart.lines.length === 0 || cart.hasExcludedLines) {
    return (
      <div className="flex flex-col items-start gap-1">
        <p className="text-lg">
          {cart.lines.length === 0
            ? "Twój koszyk jest pusty."
            : "Część pozycji w koszyku nie może zostać kupiona. Usuń albo popraw oznaczone pozycje."}
        </p>
        <Button asChild variant="link" className="px-0 underline">
          <Link href="/cart">Przejdź do koszyka</Link>
        </Button>
        {cart.lines.length === 0 && pendingOrderNumber !== null ? (
          <Button asChild variant="link" className="px-0 underline">
            <Link href="/checkout/confirmation">Opłać zamówienie {pendingOrderNumber}</Link>
          </Button>
        ) : null}
      </div>
    );
  }

  return (
    <>
      <OrderSummary cart={cart} />
      <DiscountCode
        state={code}
        disabled={submitting}
        onInput={onCodeInput}
        onApply={onApplyCode}
        onRemove={onRemoveCode}
      />
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
        {session === null ? (
          <p className="text-sm text-muted-foreground">
            Masz konto?{" "}
            <a href={LOGIN_HREF} className="text-link underline underline-offset-4">
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
        {providers === null || providers.length === 0 ? (
          <Alert role="status">
            <AlertDescription>Płatności są chwilowo niedostępne. Spróbuj za chwilę.</AlertDescription>
          </Alert>
        ) : (
          <>
            <ProviderChoice providers={providers} value={provider} error={providerError} onChange={onProviderChange} />
            <Button type="submit" className="self-end" disabled={submitting || code.busy}>
              Zamawiam z obowiązkiem zapłaty
            </Button>
          </>
        )}
      </form>
    </>
  );
}

function ProviderChoice({
  providers,
  value,
  error,
  onChange,
}: {
  providers: PaymentProvider[];
  value: string | null;
  error: string | null;
  onChange: (code: string) => void;
}) {
  return (
    <div
      role="radiogroup"
      aria-labelledby="checkout-provider-label"
      aria-required="true"
      aria-invalid={error ? true : undefined}
      aria-describedby={error ? PROVIDER_ERROR_ID : undefined}
      className="flex flex-col gap-1"
    >
      <span id="checkout-provider-label" className="mb-1 text-ui font-semibold">
        Sposób płatności
      </span>
      {providers.map(({ code, name }) => (
        <Label key={code} htmlFor={`${PROVIDER_ID_PREFIX}${code}`} className="min-h-11 gap-3 text-ui font-normal">
          <input
            id={`${PROVIDER_ID_PREFIX}${code}`}
            type="radio"
            name="provider"
            value={code}
            checked={value === code}
            onChange={() => onChange(code)}
            className="size-5 accent-foreground"
          />
          {name}
        </Label>
      ))}
      {error ? (
        <p id={PROVIDER_ERROR_ID} className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
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
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
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

type CodeState = { input: string; applied: boolean; error: string | null; status: string; busy: boolean };

/**
 * The discount code of the checkout, a form of its own so that Enter applies the code and does not order. Applied, it is
 * replaced by the button that removes the code (the line of the code is in the summary); the live region announces
 * what happened to the code.
 */
function DiscountCode({
  state,
  disabled,
  onInput,
  onApply,
  onRemove,
}: {
  state: CodeState;
  disabled: boolean;
  onInput: (value: string) => void;
  onApply: (event: FormEvent<HTMLFormElement>) => void;
  onRemove: () => void;
}) {
  const locked = disabled || state.busy;
  return (
    <div className="flex flex-col gap-2">
      <div data-testid="checkout-code-status" role="status" className="sr-only">
        {state.status}
      </div>
      {state.applied ? (
        <Button id={CODE_REMOVE_ID} type="button" variant="outline" className="self-start" disabled={locked} onClick={onRemove}>
          Usuń kod
        </Button>
      ) : (
        <form noValidate onSubmit={onApply} className="flex flex-col gap-1.5">
          <Label htmlFor={CODE_INPUT_ID}>Kod rabatowy</Label>
          <div className="flex gap-2">
            <Input
              id={CODE_INPUT_ID}
              name="discountCode"
              type="text"
              autoComplete="off"
              autoCapitalize="characters"
              maxLength={CODE_MAX_LENGTH}
              value={state.input}
              aria-invalid={state.error ? true : undefined}
              aria-describedby={state.error ? CODE_ERROR_ID : undefined}
              onChange={(event) => onInput(event.target.value)}
            />
            <Button type="submit" variant="outline" disabled={locked} aria-busy={state.busy || undefined}>
              Zastosuj
            </Button>
          </div>
        </form>
      )}
      {state.error ? (
        <Alert id={CODE_ERROR_ID} variant="destructive">
          <AlertDescription>{state.error}</AlertDescription>
        </Alert>
      ) : null}
    </div>
  );
}

/** Lines and total exactly as the API gave them; nothing is computed here. */
function OrderSummary({ cart }: { cart: CartView }) {
  return (
    <section aria-label="Podsumowanie zamówienia">
      <Card>
        <CardContent className="flex flex-col gap-3">
          <ul className="flex flex-col divide-y divide-border">
            {cart.lines.map((line) => (
              <li key={line.variantId} className="flex justify-between gap-4 py-2 first:pt-0">
                <div className="flex flex-col">
                  <span className="font-semibold">{line.product?.name}</span>
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
                  <span data-testid="checkout-line-total" className="font-semibold">
                    {formatPrice(line.lineTotal.gross, line.lineTotal.currency)}
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
          {cart.discount?.status === "applied" ? (
            <p data-testid="checkout-discount" className="flex justify-between text-sm">
              Kod {cart.discount.code}: −{formatPrice(cart.discount.amountGross, cart.totals.currency)}
            </p>
          ) : null}
          <Separator />
          <p className="flex justify-between text-lg font-semibold">
            <span>Do zapłaty (brutto)</span>
            <span data-testid="checkout-total">{formatPrice(cart.totals.gross, cart.totals.currency)}</span>
          </p>
        </CardContent>
      </Card>
    </section>
  );
}
