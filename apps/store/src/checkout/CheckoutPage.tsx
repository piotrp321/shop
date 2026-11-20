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
const CHOOSE_DELIVERY = "Wybierz sposób dostawy.";
/** What the summary says instead of the cost of the delivery and of the total until a method is chosen. */
const CHOOSE_DELIVERY_SHORT = "Wybierz sposób dostawy";
const DELIVERY_ID_PREFIX = "checkout-delivery-";
const DELIVERY_ERROR_ID = "checkout-delivery-error";
const PROVIDER_ID_PREFIX = "checkout-provider-";
const PROVIDER_ERROR_ID = "checkout-provider-error";
const NO_METHODS = "Nie możemy teraz przyjąć zamówienia: brak dostępnych sposobów dostawy. Spróbuj później.";
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
/** The limit of code checks of the API (429 of the preview of a code). */
const CODE_RATE_LIMITED = "Zbyt wiele prób sprawdzenia kodu. Spróbuj ponownie za kilka minut.";
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
 * provider, the choice of the delivery method among the options of the cart, and the order of the whole cart through
 * `/api/checkout`, which also starts the payment: the browser then goes to the page of the provider, or to the
 * confirmation page when the payment could not be started. Every amount comes from the API; the expected total is
 * `totalGross` of the chosen delivery option of the summary shown. A signed-in customer orders with the e-mail of the
 * account.
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
  // The id of the chosen delivery method, no default. The option is looked up in the summary shown: a method that is
  // no longer among its options is no choice.
  const [deliveryMethodId, setDeliveryMethodId] = useState<string | null>(null);
  const [deliveryError, setDeliveryError] = useState<string | null>(null);
  // What the live region of the summary says about the delivery after the summary was read again (a code, a 409).
  const [summaryStatus, setSummaryStatus] = useState("");
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
  // form or of the delivery method, after `IdempotencyKeyReused`, `OrderTotalChanged` (the request now carries another
  // total) and an unavailable delivery method, and when a code is applied or removed or refused (the request carries
  // another code and total).
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  useEffect(() => {
    let active = true;
    void loadCheckout().then((result) => {
      if (!active) return;
      setLoaded(result);
      if (result.status === "ready") {
        setForm(prefilled(result.session));
        setDeliveryMethodId(onlyMethod(result.cart));
      }
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
      if (result.status === "ready") {
        setForm(prefilled(result.session));
        setDeliveryMethodId(onlyMethod(result.cart));
      }
    });
  }

  /**
   * Shows the summary read again. The chosen delivery method stays only while the new summary offers it (a method that
   * is gone is not chosen again when it comes back), and a changed delivery is announced in the live region.
   */
  function showCart(next: CartView, session: AccountSession) {
    if (loaded.status === "ready") setSummaryStatus(deliveryAnnouncement(loaded.cart, next, deliveryMethodId));
    setLoaded({ status: "ready", cart: next, session });
    setDeliveryMethodId((current) => (next.delivery?.options.some((option) => option.methodId === current) ? current : null));
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
    showCart(preview.cart, session);
    setAppliedCode(preview.appliedCode);
    setCodeError(preview.codeError);
    // A code that came off the order other than by „Usuń kod” must not stay announced as applied; the message at the
    // field (role="alert") tells why. A read that failed returns above and leaves the announcement: the code is still on.
    if (preview.appliedCode === null && appliedCode !== null) setCodeStatus("");
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
      showCart(preview.cart, loaded.session);
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
      showCart(preview.cart, loaded.session);
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

  function chooseDelivery(methodId: string) {
    setDeliveryMethodId(methodId);
    setDeliveryError(null);
    setIdempotencyKey(newIdempotencyKey());
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loaded.status !== "ready" || submitting || codeBusy || providers === null || providers.length === 0) return;
    if (offersNoMethods(loaded.cart)) return;
    const delivery = chosenOption(loaded.cart, deliveryMethodId);
    if (delivery === null) {
      setDeliveryError(CHOOSE_DELIVERY);
      const first = loaded.cart.delivery?.options[0];
      setAttention((current) => ({ id: first === undefined ? DELIVERY_ERROR_ID : `${DELIVERY_ID_PREFIX}${first.methodId}`, seq: (current?.seq ?? 0) + 1 }));
      return;
    }
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
      expectedTotalGross: delivery.totalGross,
      deliveryMethodId: delivery.methodId,
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
        if (result.reason === "too_many_unpaid_orders") {
          // Nothing was placed and the cart is fine: the customer tries again later with the same request.
          setFailure({ kind: "conflict", detail, offersCart: false });
          return FAILURE_ID;
        }
        if (result.reason === "key_reused") {
          // The key belongs to another request: the form is fine, the next click is a new attempt.
          setIdempotencyKey(newIdempotencyKey());
          setFailure({ kind: "conflict", detail, offersCart: false });
          return FAILURE_ID;
        }
        // Nothing was placed; the summary below is the new total, or has the methods available now (a method that is
        // gone is no longer chosen), so the next click is a new request.
        const fixedInTheCheckout = result.reason === "total_changed" || result.reason === "delivery_method_unavailable";
        if (fixedInTheCheckout) {
          setIdempotencyKey(newIdempotencyKey());
        }
        setFailure({ kind: "conflict", detail, offersCart: !fixedInTheCheckout });
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
          deliveryMethodId={deliveryMethodId}
          deliveryError={deliveryError}
          summaryStatus={summaryStatus}
          onDeliveryChange={chooseDelivery}
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
 * plain summary and the API's text for the field, a 429 of the limit of code checks the plain summary and the text of
 * the limit; a rejected one gives the summary without a discount and its `detail`.
 * Nothing is computed here: whether a code applies, and what it takes off, is the API's.
 */
async function loadPreview(code: string | null): Promise<Preview> {
  const result = await loadCart(...(code === null ? [] : [code]));
  if (result.status === "unauthorized") return { status: "unauthorized" };
  if (result.status === "invalid" || result.status === "rate_limited") {
    const plain = await loadCart();
    if (plain.status === "unauthorized") return { status: "unauthorized" };
    const codeError = result.status === "rate_limited" ? CODE_RATE_LIMITED : (result.detail ?? CODE_NOT_CHECKED);
    return plain.status === "ok" ? { status: "ok", cart: plain.data, appliedCode: null, codeError } : { status: "failed" };
  }
  if (result.status !== "ok") return { status: "failed" };
  if (code === null) return { status: "ok", cart: result.data, appliedCode: null, codeError: null };
  const discount = result.data.discount;
  if (discount?.status === "applied") return { status: "ok", cart: result.data, appliedCode: code, codeError: null };
  return { status: "ok", cart: result.data, appliedCode: null, codeError: discount?.status === "rejected" ? discount.detail : CODE_BLANK };
}

type DeliveryOption = NonNullable<CartView["delivery"]>["options"][number];

/** The option of the chosen method in the summary shown; none without a choice or when the method is not offered. */
function chosenOption(cart: CartView, methodId: string | null): DeliveryOption | null {
  return cart.delivery?.options.find((option) => option.methodId === methodId) ?? null;
}

/** The id of the method when the cart offers exactly one: the only one that is chosen for the customer. */
function onlyMethod(cart: CartView): string | null {
  return cart.delivery?.options.length === 1 ? cart.delivery.options[0].methodId : null;
}

/** A cart to buy that has no delivery method to choose: none is active, or the API is the one from before the delivery. */
function offersNoMethods(cart: CartView): boolean {
  return cart.delivery === undefined || (cart.delivery !== null && cart.delivery.options.length === 0);
}

/**
 * What the live region says when the summary was read again: a delivery that stopped being free (with what is missing
 * to it) or became free, and a changed amount missing to free delivery. Only the chosen method is told about when one
 * is chosen and still offered, else every method. The amounts are the API's.
 */
function deliveryAnnouncement(before: CartView, after: CartView, chosenId: string | null): string {
  const options = after.delivery?.options ?? [];
  const relevant = options.some((option) => option.methodId === chosenId) ? options.filter((option) => option.methodId === chosenId) : options;
  const currency = after.totals.currency;
  const sentences: string[] = [];
  for (const option of relevant) {
    const was = before.delivery?.options.find((candidate) => candidate.methodId === option.methodId);
    if (was === undefined) continue;
    const missing = option.missingToFreeGross === null ? "" : ` Do darmowej dostawy brakuje ${formatPrice(option.missingToFreeGross, currency)}.`;
    if (was.costGross === 0 && option.costGross > 0) {
      sentences.push(`Dostawa „${option.name}” nie jest już darmowa, kosztuje ${formatPrice(option.costGross, currency)}.${missing}`);
    } else if (was.costGross > 0 && option.costGross === 0) {
      sentences.push(`Dostawa „${option.name}” jest teraz darmowa.`);
    } else if (option.costGross > 0 && was.missingToFreeGross !== option.missingToFreeGross && option.missingToFreeGross !== null) {
      sentences.push(`Dostawa „${option.name}”:${missing}`);
    }
  }
  return sentences.join(" ");
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
  deliveryMethodId,
  deliveryError,
  summaryStatus,
  onDeliveryChange,
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
  deliveryMethodId: string | null;
  deliveryError: string | null;
  summaryStatus: string;
  onDeliveryChange: (methodId: string) => void;
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
      <OrderSummary cart={cart} delivery={chosenOption(cart, deliveryMethodId)} status={summaryStatus} />
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
        {offersNoMethods(cart) ? (
          <Alert role="status">
            <AlertDescription>{NO_METHODS}</AlertDescription>
          </Alert>
        ) : cart.delivery ? (
          <DeliveryChoice
            options={cart.delivery.options}
            value={chosenOption(cart, deliveryMethodId)?.methodId ?? null}
            error={deliveryError}
            currency={cart.totals.currency}
            onChange={onDeliveryChange}
          />
        ) : null}
        {providers === null || providers.length === 0 ? (
          <Alert role="status">
            <AlertDescription>Płatności są chwilowo niedostępne. Spróbuj za chwilę.</AlertDescription>
          </Alert>
        ) : (
          <>
            <ProviderChoice providers={providers} value={provider} error={providerError} onChange={onProviderChange} />
            <Button type="submit" className="self-end" disabled={submitting || code.busy || offersNoMethods(cart)}>
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

/**
 * The delivery methods of the cart as the API priced them, in a required group with no default: the name, the
 * description, the cost or „Darmowa”, and what is missing to free delivery (never with „Darmowa”, the API says so).
 */
function DeliveryChoice({
  options,
  value,
  error,
  currency,
  onChange,
}: {
  options: DeliveryOption[];
  value: string | null;
  error: string | null;
  currency: string;
  onChange: (methodId: string) => void;
}) {
  return (
    <fieldset
      aria-invalid={error ? true : undefined}
      aria-describedby={error ? DELIVERY_ERROR_ID : undefined}
      className="flex min-w-0 flex-col gap-1"
    >
      <legend className="mb-1 text-ui font-semibold">Sposób dostawy</legend>
      {options.map((option) => (
        <Label key={option.methodId} htmlFor={`${DELIVERY_ID_PREFIX}${option.methodId}`} className="min-h-11 items-start gap-3 py-1 text-ui font-normal">
          <input
            id={`${DELIVERY_ID_PREFIX}${option.methodId}`}
            type="radio"
            name="deliveryMethod"
            required
            value={option.methodId}
            checked={value === option.methodId}
            onChange={() => onChange(option.methodId)}
            className="mt-0.5 size-5 shrink-0 accent-foreground"
          />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="flex flex-wrap justify-between gap-x-4">
              <span className="font-semibold break-words">{option.name}</span>
              <span>{option.costGross === 0 ? "Darmowa" : formatPrice(option.costGross, currency)}</span>
            </span>
            {option.description ? <span className="text-sm text-muted-foreground break-words">{option.description}</span> : null}
            {option.costGross !== 0 && option.missingToFreeGross !== null ? (
              <span className="text-sm text-muted-foreground">Do darmowej dostawy brakuje {formatPrice(option.missingToFreeGross, currency)}</span>
            ) : null}
          </span>
        </Label>
      ))}
      {error ? (
        <p id={DELIVERY_ERROR_ID} tabIndex={-1} className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </fieldset>
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

/**
 * Lines, the delivery and the total exactly as the API gave them; nothing is computed here. With delivery methods the
 * total to pay is `totalGross` of the chosen option, and without a choice the summary asks for one instead.
 */
function OrderSummary({ cart, delivery, status }: { cart: CartView; delivery: DeliveryOption | null; status: string }) {
  const offersDelivery = (cart.delivery?.options.length ?? 0) > 0;
  return (
    <section aria-label="Podsumowanie zamówienia">
      <div data-testid="checkout-summary-status" role="status" className="sr-only">
        {status}
      </div>
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
          {offersDelivery ? (
            <p className="flex justify-between gap-4 text-sm">
              <span>Dostawa</span>
              <span data-testid="checkout-delivery">
                {delivery === null ? CHOOSE_DELIVERY_SHORT : delivery.costGross === 0 ? "Darmowa" : formatPrice(delivery.costGross, cart.totals.currency)}
              </span>
            </p>
          ) : null}
          <Separator />
          <p className="flex justify-between gap-4 text-lg font-semibold">
            <span>Do zapłaty (brutto)</span>
            <span data-testid="checkout-total">
              {!offersDelivery
                ? formatPrice(cart.totals.gross, cart.totals.currency)
                : delivery === null
                  ? CHOOSE_DELIVERY_SHORT
                  : formatPrice(delivery.totalGross, cart.totals.currency)}
            </span>
          </p>
        </CardContent>
      </Card>
    </section>
  );
}
