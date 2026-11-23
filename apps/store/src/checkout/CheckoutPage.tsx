"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, type KeyboardEvent, type MouseEvent, useEffect, useRef, useState } from "react";
import {
  type AccountSession,
  type CartView,
  loadAccountSession,
  loadCart,
  type PlaceOrderInput,
  type PlaceOrderResult,
  placeOrder,
} from "@/api/client";
import { actionsFinished } from "@/cart/cart-actions";
import { announceCartChange } from "@/cart/events";
import { SessionExpired } from "@/cart/messages";
import { formatPrice } from "@/catalog/format-price";
import { ScreenMain, ScreenTitle } from "@/components/ScreenLayout";
import { useSiteSwitches } from "@/components/SiteSwitches";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";
import { GUEST_BUYER } from "./buyer";
import { cartChanges } from "./cart-changes";
import { navigateTo } from "./navigate";
import type { PaymentProvider } from "./payment-api";

type Loaded =
  | { status: "loading" }
  | { status: "error" }
  | { status: "unauthorized" }
  | { status: "ready"; cart: CartView; session: AccountSession };

type Form = { email: string; fullName: string; phone: string; street: string; postalCode: string; city: string };
type FieldName = keyof Form;

/** The refusals after which the checkout reads the cart and tells what changed (#852). */
type ChangeReason = "cart_changed" | "total_changed";

/** Why the last order was not placed; each is shown above the form (see the UI table of the checkout spec). */
type Failure =
  | { kind: "conflict"; detail: string; offersCart: boolean }
  | { kind: "changed"; reason: ChangeReason; changes: string[] }
  /**
   * The buyer changed: `email` is the account of the session read, null when it is signed out. `mayHavePlaced`: the
   * refused request had the key of an attempt whose result was uncertain, which may have placed the order.
   */
  | { kind: "buyer"; email: string | null; mayHavePlaced: boolean }
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
/** The first paragraph of the message of a changed cart or total; the API's `detail` is not shown. */
const CHANGED: Record<ChangeReason, string> = {
  cart_changed: "Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.",
  total_changed: "Kwota zamówienia zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.",
};
const CHANGED_LAST = "Sprawdź podsumowanie i zamów ponownie.";
const CHANGES_NOT_READ = "Koszyk albo kwota zamówienia się zmieniły, ale nie udało się wczytać aktualnego podsumowania. Spróbuj ponownie.";
/** What a refusal for a changed buyer says of the order: none was placed, or the attempt before may have placed one. */
const PLACED_NOTHING = "Nie złożyliśmy zamówienia.";
const MAY_HAVE_PLACED = "Poprzednia próba mogła złożyć zamówienie dla poprzedniego kupującego. Sprawdź pocztę, zanim zamówisz ponownie.";
const SIGNED_IN_AS = (email: string, placed: string) =>
  `Od wczytania kasy zmieniło się konto: teraz to ${email}. ${placed} Zamówienie będzie zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.`;
const SIGNED_OUT = (placed: string) => `Od wczytania kasy nastąpiło wylogowanie. ${placed} Podaj adres e-mail, sprawdź dane i podsumowanie, potem zamów ponownie.`;
const BUYER_NOT_READ = "Kupujący się zmienił, ale nie udało się wczytać kasy. Spróbuj ponownie.";
const FIELD_ID_PREFIX = "checkout-";
const FAILURE_ID = "checkout-failure";
const TITLE_ID = "checkout-title";
const RETRY_ID = "checkout-retry";
const LOAD_FAILED = "Nie udało się wczytać kasy";
/** What the status region says while the checkout is read. */
const LOADING = "Wczytywanie…";
/** What the status region says from the order being sent to its refusal, or to leaving the page after a success. */
const PLACING = "Składamy zamówienie…";
/** The keys that change the choice of a radio of a group: the arrows move it, Space chooses the focused one. */
const CHOICE_KEYS = new Set(["ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", " "]);
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
  const { customerAccounts, loaded: switchesLoaded } = useSiteSwitches();
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
  // The same choice as it is now, for what a read of the summary does when its answer comes: the method is not locked
  // while a code is checked, so the render of the click may hold an older one. Set only by `chooseMethod`.
  const chosenMethod = useRef<string | null>(null);
  const [deliveryError, setDeliveryError] = useState<string | null>(null);
  // What the live region of the summary says about the delivery after the summary was read again (a code, a 409).
  const [summaryStatus, setSummaryStatus] = useState("");
  const [submitting, setSubmitting] = useState(false);
  // Whether the errors of the fields and of the provider of the last 422 are alerts: only when the focus did not go to
  // the first wrong field, because the customer had moved it during the request (F2), so nothing else would say them.
  const [errorsAnnounced, setErrorsAnnounced] = useState(false);
  // "Spróbuj ponownie" reads the checkout again while the error stays (the button keeps the focus); `loaded` says
  // "loading" only for the first read.
  const [retrying, setRetrying] = useState(false);
  // The summary is out of date: a refusal for a changed cart or total could not read the cart. Until the customer is
  // told what changed against `shown`, the summary of the refused order, nothing is ordered: the next click on the
  // order button reads the cart and tells the changes instead (`checking` is that read, with the form locked), and any
  // other read of the summary (a code) tells them at once (`showCart`). The same after a refusal for a changed buyer
  // whose reads failed: the next click reads the session and the cart (a read of a code reads no session, so it leaves
  // the state), and its message keeps what the refusal knew of an uncertain attempt before. A ref, not a state: a read of a code, or a click, after the failed read but before its render must see it.
  const outOfDate = useRef<
    { reason: ChangeReason; shown: CartView } | { reason: "buyer_changed"; shown: CartView; mayHavePlaced: boolean } | null
  >(null);
  const [checking, setChecking] = useState(false);
  // The discount code lives in this component only (D3): a reload of the page starts without one. `appliedCode` is the
  // code the summary of `loaded` was priced with; `codeInput` is what the customer types.
  const [codeInput, setCodeInput] = useState("");
  const [appliedCode, setAppliedCode] = useState<string | null>(null);
  const [codeError, setCodeError] = useState<string | null>(null);
  const [codeStatus, setCodeStatus] = useState("");
  const [codeBusy, setCodeBusy] = useState(false);
  // The request on its way, read by the handlers: the order or a check of the code. One at a time; while the order runs
  // nothing of the form changes. The states above render the same, but a second event may come before they do.
  const running = useRef<"order" | "code" | null>(null);
  // Where the focus goes after an answer: the message, a field, the code. Set only under the condition of F2
  // (`focusStayed`), or at once when nothing was sent.
  const [attention, setAttention] = useState<{ id: string; seq: number } | null>(null);
  // The key of one logical attempt. Made on entry and kept for a retry of an uncertain result (a network error, 403,
  // 5xx), after a 429 of the rate limit and after a 409 that leaves the request as it was. A new one for a new attempt: after every change of the
  // form or of the delivery method, after `IdempotencyKeyReused`, `OrderTotalChanged` (the request now carries another
  // total) and an unavailable delivery method, and when a code is applied or removed or refused (the request carries
  // another code and total).
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);
  // The key of the last attempt whose result was uncertain (`failed`: a network error, 403, 5xx, an answer without the
  // order), which may have placed the order; null after a new key. A refusal of the BFF before the API on that key (a
  // changed buyer) cannot tell whether that attempt placed it. A ref: read when the answer comes, not from the render.
  const uncertainKey = useRef<string | null>(null);

  /** Starts a new attempt: a new key, of which nothing is uncertain yet. */
  function renewKey() {
    uncertainKey.current = null;
    setIdempotencyKey(newIdempotencyKey());
  }

  useEffect(() => {
    let active = true;
    // A change given on the cart page of this tab while the navigation here was on its way is sent by that page after it
    // was left: the cart is read after it, with the page loading till then (docs/features/checkout/spec.md).
    void actionsFinished()
      .then(loadCheckout)
      .then((result) => {
        if (!active) return;
        setLoaded(result);
        if (result.status === "ready") {
          setForm(prefilled(result.session));
          // `chooseMethod`, spelled out: the effect runs once and depends on nothing of the render.
          chosenMethod.current = onlyMethod(result.cart);
          setDeliveryMethodId(chosenMethod.current);
        }
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (attention) document.getElementById(attention.id)?.focus();
  }, [attention]);

  function moveFocusTo(id: string) {
    setAttention((current) => ({ id, seq: (current?.seq ?? 0) + 1 }));
  }

  /** Moves the focus to `id` after an answer only when it stayed on `origin`, where the action came from, or was lost. */
  function focusAfterAnswer(origin: Element | null, id: string) {
    if (focusStayed(origin)) moveFocusTo(id);
  }

  async function retry() {
    if (retrying) return;
    setRetrying(true);
    const result = await loadCheckout();
    // The focus is moved only when it is still on the button the retry came from, or was lost with it (F2).
    const keepsFocus = focusStayed(document.getElementById(RETRY_ID));
    setRetrying(false);
    setLoaded(result);
    if (result.status === "ready") {
      setForm(prefilled(result.session));
      chooseMethod(onlyMethod(result.cart));
    }
    if (keepsFocus) moveFocusTo(result.status === "error" ? RETRY_ID : TITLE_ID);
  }

  /**
   * Shows the summary read again. The chosen delivery method stays only while the new summary offers it (a method that
   * is gone is not chosen again when it comes back), and a changed delivery is announced in the live region. Every
   * read of the summary goes through here: one read while the summary is out of date comes with the message of what
   * changed against the summary of the refused order, so a summary the customer was not told about is never ordered
   * and the message of the failed read does not stay. The method of the announcement and of the list is the one chosen
   * when the answer comes (`chosenMethod`), the one the summary and the next order have; the state out of date is the
   * one at that moment too.
   */
  function showCart(next: CartView, session: AccountSession) {
    const chosen = chosenMethod.current;
    if (loaded.status === "ready") setSummaryStatus(deliveryAnnouncement(loaded.cart, next, chosen));
    setLoaded({ status: "ready", cart: next, session });
    const stale = outOfDate.current;
    if (stale !== null && stale.reason !== "buyer_changed") {
      setFailure({ kind: "changed", reason: stale.reason, changes: cartChanges(stale.shown, next, chosen) });
      outOfDate.current = null;
    }
    chooseMethod(next.delivery?.options.some((option) => option.methodId === chosen) ? chosen : null);
  }

  /**
   * Takes off the message of the changes (`changed`) when the customer changed the summary after it (a code applied or
   * removed, another delivery method): it tells the difference at the moment of the refused order and would contradict
   * the summary next to it. Other messages stay, and so does the focus. Called before a read shows its summary, so that a
   * read of a summary out of date still shows its own list (`showCart`); the message is the one at that moment.
   */
  function dismissChanges() {
    setFailure((current) => (current?.kind === "changed" ? null : current));
  }

  /** Chooses the delivery method (none: `null`); the only place that changes the choice. */
  function chooseMethod(methodId: string | null) {
    chosenMethod.current = methodId;
    setDeliveryMethodId(methodId);
  }

  function change(name: FieldName, value: string) {
    if (running.current === "order") return;
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
    renewKey();
  }

  /** Shows a summary read again and what the API says of its code. */
  function showPreview(preview: Extract<Preview, { status: "ok" }>, session: AccountSession) {
    showCart(preview.cart, session);
    setAppliedCode(preview.appliedCode);
    setCodeError(preview.codeError);
    // A code that came off the order other than by „Usuń kod” must not stay announced as applied; the message at the
    // field (role="alert") tells why. A read that failed shows nothing and leaves the announcement: the code is still on.
    if (preview.appliedCode === null && appliedCode !== null) setCodeStatus("");
    announceCartChange(preview.cart.totals.itemCount);
  }

  /** Reads the summary again with `code` and shows what the API says of it; answers how the read ended. */
  async function reloadPreview(code: string | null, session: AccountSession): Promise<Preview["status"]> {
    const preview = await loadPreview(code);
    if (preview.status === "unauthorized") {
      setFailure({ kind: "unauthorized" });
      return "unauthorized";
    }
    if (preview.status === "failed") return "failed";
    showPreview(preview, session);
    return "ok";
  }

  /**
   * Reads the cart, with the code applied, after a refusal for a changed cart or total, or for a click on a summary out
   * of date, and only then shows the message: the changes between the summary `shown` (with the method `chosenId`) and
   * the one read, and that summary, come in one render, and so does the end of the lock of the order. A read that
   * fails, or answers 401, leaves the summary out of date against `shown`. Answers the id of the element that gets the
   * focus.
   */
  async function readChanges(reason: ChangeReason, shown: CartView, chosenId: string | null, session: AccountSession): Promise<string> {
    const preview = await loadPreview(appliedCode);
    if (preview.status !== "ok") {
      outOfDate.current = { reason, shown };
      setFailure(preview.status === "unauthorized" ? { kind: "unauthorized" } : { kind: "message", text: CHANGES_NOT_READ });
    } else {
      showPreview(preview, session);
      setFailure({ kind: "changed", reason, changes: cartChanges(shown, preview.cart, chosenId) });
    }
    unlockOrder();
    return FAILURE_ID;
  }

  /**
   * Reads the session and the cart, with the code applied, after a refusal for a changed buyer, or for a click on a
   * checkout out of date for one, and only then shows the message: the buyer of the session read (the address of the
   * account, or the e-mail field), the summary read and the message come in one render, and so does the end of the
   * lock of the order. The form keeps what was typed; the next order carries `buyer` of this read. A read that fails
   * (the session or the cart), or a 401 of the cart, leaves the checkout out of date. Answers the id of the element
   * that gets the focus.
   */
  async function readBuyer(shown: CartView, mayHavePlaced: boolean): Promise<string> {
    const [preview, session] = await Promise.all([loadPreview(appliedCode), loadAccountSession()]);
    if (preview.status === "ok" && session.status === "ok") {
      outOfDate.current = null;
      showPreview(preview, session.session);
      setFailure({ kind: "buyer", email: session.session?.email ?? null, mayHavePlaced });
    } else {
      outOfDate.current = { reason: "buyer_changed", shown, mayHavePlaced };
      setFailure(preview.status === "unauthorized" ? { kind: "unauthorized" } : { kind: "message", text: BUYER_NOT_READ });
    }
    unlockOrder();
    return FAILURE_ID;
  }

  /** Ends the lock of the form taken for an order, or for the read of a summary out of date. */
  function unlockOrder() {
    running.current = null;
    setSubmitting(false);
    setChecking(false);
  }

  async function applyCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loaded.status !== "ready" || running.current !== null) return;
    const code = codeInput.trim();
    if (code === "") {
      setCodeError(CODE_BLANK);
      moveFocusTo(CODE_INPUT_ID);
      return;
    }
    // The button "Zastosuj", or the field Enter was pressed in.
    const origin = document.activeElement;
    running.current = "code";
    setCodeBusy(true);
    setCodeError(null);
    const preview = await loadPreview(code);
    running.current = null;
    setCodeBusy(false);
    let target = CODE_INPUT_ID;
    if (preview.status === "ok") {
      if (preview.appliedCode !== null) dismissChanges();
      showCart(preview.cart, loaded.session);
      setAppliedCode(preview.appliedCode);
      setCodeError(preview.codeError);
      if (preview.appliedCode !== null) {
        // The request now carries a code and another total; a rejected code leaves it as it was.
        renewKey();
        setCodeStatus(`Zastosowano kod ${preview.cart.discount?.code ?? preview.appliedCode}.`);
        target = CODE_REMOVE_ID;
      }
    } else if (preview.status === "unauthorized") {
      setFailure({ kind: "unauthorized" });
      target = FAILURE_ID;
    } else {
      setCodeError(CODE_NOT_CHECKED);
    }
    focusAfterAnswer(origin, target);
  }

  async function removeCode() {
    if (loaded.status !== "ready" || running.current !== null) return;
    const origin = document.activeElement;
    running.current = "code";
    setCodeBusy(true);
    setCodeError(null);
    const preview = await loadPreview(null);
    running.current = null;
    setCodeBusy(false);
    let target = CODE_REMOVE_ID;
    if (preview.status === "ok") {
      dismissChanges();
      showCart(preview.cart, loaded.session);
      setAppliedCode(null);
      setCodeInput("");
      setCodeStatus("Usunięto kod rabatowy.");
      renewKey();
      target = CODE_INPUT_ID;
    } else if (preview.status === "unauthorized") {
      setFailure({ kind: "unauthorized" });
      target = FAILURE_ID;
    } else {
      setCodeError(CODE_NOT_REMOVED);
    }
    focusAfterAnswer(origin, target);
  }

  function chooseDelivery(methodId: string) {
    if (running.current === "order") return;
    if (methodId !== chosenMethod.current) dismissChanges();
    chooseMethod(methodId);
    setDeliveryError(null);
    renewKey();
  }

  function chooseProvider(code: string) {
    if (running.current === "order") return;
    setProvider(code);
    setProviderError(null);
  }

  function typeCode(value: string) {
    if (running.current === "order") return;
    setCodeInput(value);
    setCodeError(null);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loaded.status !== "ready" || running.current !== null || providers === null || providers.length === 0) return;
    if (offersNoMethods(loaded.cart)) return;
    const stale = outOfDate.current;
    if (stale !== null) {
      // The summary shown may not be the cart any more: this click reads it and tells the changes, and orders nothing.
      const origin = document.activeElement;
      running.current = "order";
      setSubmitting(true);
      setChecking(true);
      setFailure(null);
      const target =
        stale.reason === "buyer_changed"
          ? await readBuyer(stale.shown, stale.mayHavePlaced)
          : await readChanges(stale.reason, stale.shown, chosenMethod.current, loaded.session);
      focusAfterAnswer(origin, target);
      return;
    }
    const delivery = chosenOption(loaded.cart, deliveryMethodId);
    // Both missing choices are told at once; the focus goes to the first group with a message.
    if (delivery === null || provider === null) {
      if (delivery === null) setDeliveryError(CHOOSE_DELIVERY);
      if (provider === null) setProviderError(CHOOSE_PROVIDER);
      setErrorsAnnounced(false);
      const first = loaded.cart.delivery?.options[0];
      if (delivery !== null) moveFocusTo(`${PROVIDER_ID_PREFIX}${providers[0].code}`);
      else moveFocusTo(first === undefined ? DELIVERY_ERROR_ID : `${DELIVERY_ID_PREFIX}${first.methodId}`);
      return;
    }
    // The button, or the field Enter was pressed in: the focus moves after a refusal only when it stayed there (F2).
    const origin = document.activeElement;
    running.current = "order";
    setSubmitting(true);
    setProviderError(null);
    setFailure(null);
    setFieldErrors({});
    setErrorsAnnounced(false);
    const { email, ...contact } = form;
    const input: PlaceOrderInput = {
      ...(loaded.session === null ? { email } : {}),
      ...contact,
      expectedTotalGross: delivery.totalGross,
      deliveryMethodId: delivery.methodId,
      // The lines of the summary shown, only compared by the API with the cart.
      expectedLines: loaded.cart.lines.map((line) => ({ variantId: line.variantId, quantity: line.quantity })),
      provider,
      ...(appliedCode === null ? {} : { discountCode: appliedCode }),
      // The buyer of the render the click came from: the one on the screen, from the last read of the session.
      buyer: loaded.session === null ? GUEST_BUYER : loaded.session.buyer,
    };
    // The key of this request: the one of the render the click came from, which nothing changes while the form is locked.
    const key = idempotencyKey;
    const result = await placeOrder(input, key);
    if (result.status === "ok") {
      announceCartChange(0);
      // The form stays locked: the page of the provider, or the confirmation page, is on its way.
      if (result.redirectUrl === null) {
        router.push("/checkout/confirmation");
      } else {
        navigateTo(result.redirectUrl);
      }
      return;
    }
    // Set with the errors themselves (the 422 is shown without waiting), so that they are rendered as alerts at once.
    setErrorsAnnounced(result.status === "invalid" && !focusStayed(origin));
    const target = await showFailure(result, key, loaded.session, loaded.cart, delivery.methodId);
    running.current = null;
    setSubmitting(false);
    focusAfterAnswer(origin, target);
  }

  /**
   * Shows why the order was not placed; answers the id of the element that gets the focus. `key` is the key the order
   * was sent with, `shown` its summary and `chosenId` its delivery method.
   */
  async function showFailure(
    result: Exclude<PlaceOrderResult, { status: "ok" }>,
    key: string,
    session: AccountSession,
    shown: CartView,
    chosenId: string,
  ): Promise<string> {
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
          renewKey();
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
          renewKey();
          setFailure({ kind: "conflict", detail, offersCart: false });
          return FAILURE_ID;
        }
        if (result.reason === "buyer_changed") {
          // This request placed nothing: the session is of another buyer than the one shown. An earlier attempt with the
          // same key and an uncertain result may have placed the order of the buyer shown, which the BFF, refusing before
          // the API, cannot know: the message then says so. The form stays locked while the session and the cart are
          // read; then the message tells who buys now. The request carries another buyer.
          const mayHavePlaced = uncertainKey.current === key;
          renewKey();
          return readBuyer(shown, mayHavePlaced);
        }
        if (result.reason === "cart_changed" || result.reason === "total_changed") {
          // Nothing was placed, and from now on the request carries other lines or another total. The form stays locked
          // while the cart is read; then the message tells what changed, with the summary read (#852).
          renewKey();
          return readChanges(result.reason, shown, chosenId, session);
        }
        // Nothing was placed; the summary below has the methods available now (a method that is gone is no longer
        // chosen), so the next click is a new request.
        const fixedInTheCheckout = result.reason === "delivery_method_unavailable";
        if (fixedInTheCheckout) {
          renewKey();
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
        // The order may have been placed (the answer was lost): the retry goes with the same key.
        uncertainKey.current = key;
        setFailure({ kind: "message", text: SUBMIT_FAILED });
        return FAILURE_ID;
    }
  }

  // The message that replaces the form has its own link to the cart, so a conflict does not add a second one.
  const showsForm = loaded.status === "ready" && loaded.cart.lines.length > 0 && !loaded.cart.hasExcludedLines;
  // The one status region of the screen says what the screen is doing; it is there in every state and only its text
  // changes, so that a screen reader announces the text each time it is set.
  const operation = loaded.status === "loading" || retrying || checking ? LOADING : submitting ? PLACING : "";

  return (
    <ScreenMain>
      <ScreenTitle id={TITLE_ID} tabIndex={-1}>
        Kasa
      </ScreenTitle>
      {/* An empty region takes no room: the negative margin cancels the gap above it. */}
      <p role="status" data-testid="checkout-status" className="text-muted-foreground empty:-mt-6">
        {operation}
      </p>
      {loaded.status === "unauthorized" ? (
        <Alert variant="destructive">
          <AlertDescription>
            <SessionExpired returnTo="/checkout" />
          </AlertDescription>
        </Alert>
      ) : null}
      {loaded.status === "error" ? (
        <div className="flex flex-col items-start gap-3">
          {retrying ? null : (
            <Alert variant="destructive">
              <AlertDescription>{LOAD_FAILED}</AlertDescription>
            </Alert>
          )}
          {/* A running read blocks with `aria-disabled`, not `disabled`: a disabled button loses the keyboard focus. */}
          <Button
            id={RETRY_ID}
            type="button"
            variant="outline"
            aria-disabled={retrying ? true : undefined}
            className={retrying ? "opacity-50" : undefined}
            onClick={() => void retry()}
          >
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
          errorsAnnounced={errorsAnnounced}
          providers={providers}
          provider={provider}
          providerError={providerError}
          deliveryMethodId={deliveryMethodId}
          deliveryError={deliveryError}
          summaryStatus={summaryStatus}
          offersLogin={switchesLoaded && customerAccounts}
          onDeliveryChange={chooseDelivery}
          pendingOrderNumber={pendingOrderNumber}
          submitting={submitting}
          code={{ input: codeInput, applied: appliedCode !== null, error: codeError, status: codeStatus, busy: codeBusy }}
          onCodeInput={typeCode}
          onApplyCode={(event) => void applyCode(event)}
          onRemoveCode={() => void removeCode()}
          onChange={change}
          onProviderChange={chooseProvider}
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

/**
 * Whether the focus may be moved after an answer (F2): it is still on `origin`, the element the action came from (the
 * button, or the field Enter was pressed in), or it was lost with it. Moved by the customer meanwhile, it stays there.
 */
function focusStayed(origin: Element | null): boolean {
  const focused = document.activeElement;
  return focused === null || focused === document.body || focused === origin;
}

/** The look of a field or a button blocked with `aria-disabled`: `disabled` would drop the keyboard focus. */
const BLOCKED_FIELD = "aria-disabled:cursor-not-allowed aria-disabled:border-border aria-disabled:bg-muted aria-disabled:text-muted-foreground";
const BLOCKED_BUTTON = "aria-disabled:cursor-not-allowed aria-disabled:opacity-50";
const BLOCKED_CHOICE = "cursor-not-allowed text-muted-foreground";

/**
 * What a radio of a choice gets while the order is placed: `aria-disabled`, not `disabled` (which drops the focus), and
 * no change of the choice by a click, a tap, the arrows or Space. A cancelled click leaves the browser's choice as it
 * was; the handler of the change ignores it too.
 */
function blockedRadio(locked: boolean) {
  if (!locked) return {};
  return {
    "aria-disabled": true,
    onClick: (event: MouseEvent<HTMLInputElement>) => event.preventDefault(),
    onKeyDown: (event: KeyboardEvent<HTMLInputElement>) => {
      if (CHOICE_KEYS.has(event.key)) event.preventDefault();
    },
  } as const;
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

/** The message of a changed buyer: who buys now, and whether the order may have been placed by the attempt before. */
function buyerMessage({ email, mayHavePlaced }: Extract<Failure, { kind: "buyer" }>): string {
  const placed = mayHavePlaced ? MAY_HAVE_PLACED : PLACED_NOTHING;
  return email === null ? SIGNED_OUT(placed) : SIGNED_IN_AS(email, placed);
}

function FailureMessage({ failure, cartLinkShown }: { failure: Failure; cartLinkShown: boolean }) {
  return (
    <Alert id={FAILURE_ID} variant="destructive" tabIndex={-1}>
      {/* Texts of the data (an address, a product, a variant, a delivery method) can be one word longer than the screen:
          `wrap-anywhere` (not `break-words`) also lets the grid of the alert shrink to a narrow screen, as it counts in
          the width the text needs at least. */}
      <AlertDescription className="wrap-anywhere">
        {failure.kind === "unauthorized" ? (
          <p>
            <SessionExpired returnTo="/checkout" />
          </p>
        ) : failure.kind === "message" ? (
          <p>{failure.text}</p>
        ) : failure.kind === "buyer" ? (
          <p>{buyerMessage(failure)}</p>
        ) : failure.kind === "changed" ? (
          <>
            <p>{CHANGED[failure.reason]}</p>
            {failure.changes.length > 0 ? (
              <ul className="list-disc pl-5 not-last:mb-4">
                {/* Two lines without a known product can say the same: the position is the key. */}
                {failure.changes.map((change, index) => (
                  <li key={index}>{change}</li>
                ))}
              </ul>
            ) : null}
            {/* Without the form the message of the summary has its own link to the cart: no second one. */}
            {cartLinkShown ? (
              <p>
                {CHANGED_LAST} <Link href="/cart">Przejdź do koszyka</Link>
              </p>
            ) : null}
          </>
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
  errorsAnnounced,
  providers,
  provider,
  providerError,
  deliveryMethodId,
  deliveryError,
  summaryStatus,
  offersLogin,
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
  /** Whether the errors of the fields and of the provider are alerts (see `errorsAnnounced` of the page). */
  errorsAnnounced: boolean;
  providers: PaymentProvider[] | null;
  provider: string | null;
  providerError: string | null;
  deliveryMethodId: string | null;
  deliveryError: string | null;
  summaryStatus: string;
  /** Whether a guest is offered the login: the switches are read and the accounts are on. */
  offersLogin: boolean;
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
        locked={submitting}
        onInput={onCodeInput}
        onApply={onApplyCode}
        onRemove={onRemoveCode}
      />
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
        {session === null ? (
          offersLogin ? (
            <p className="text-sm text-muted-foreground">
              Masz konto?{" "}
              <a href={LOGIN_HREF} className="text-link underline underline-offset-4">
                Zaloguj się
              </a>
            </p>
          ) : null
        ) : (
          <p className="text-sm wrap-anywhere">
            Potwierdzenie wyślemy na adres <span data-testid="account-email">{session.email}</span>
          </p>
        )}
        {FIELDS.filter((field) => field.name !== "email" || session === null).map((field) => (
          <FormField
            key={field.name}
            {...field}
            value={form[field.name]}
            error={fieldErrors[field.name]}
            announced={errorsAnnounced}
            locked={submitting}
            onChange={onChange}
          />
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
            locked={submitting}
            onChange={onDeliveryChange}
          />
        ) : null}
        {providers === null || providers.length === 0 ? (
          <Alert role="status">
            <AlertDescription>Płatności są chwilowo niedostępne. Spróbuj za chwilę.</AlertDescription>
          </Alert>
        ) : (
          <>
            <ProviderChoice
              providers={providers}
              value={provider}
              error={providerError}
              announced={errorsAnnounced}
              locked={submitting}
              onChange={onProviderChange}
            />
            {/* A running request blocks with `aria-disabled`, not `disabled`: a disabled button loses the keyboard focus.
                No delivery method to choose is a lasting state, not a request, and keeps `disabled`. */}
            <Button
              type="submit"
              className={cn("self-end", BLOCKED_BUTTON)}
              disabled={offersNoMethods(cart)}
              aria-disabled={submitting || code.busy ? true : undefined}
            >
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
  announced,
  locked,
  onChange,
}: {
  providers: PaymentProvider[];
  value: string | null;
  error: string | null;
  /** Whether the error is an alert (see `errorsAnnounced`). */
  announced: boolean;
  /** While the order is placed the choice does not change. */
  locked: boolean;
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
        <Label key={code} htmlFor={`${PROVIDER_ID_PREFIX}${code}`} className={cn("min-h-11 gap-3 text-ui font-normal", locked && BLOCKED_CHOICE)}>
          <input
            id={`${PROVIDER_ID_PREFIX}${code}`}
            type="radio"
            name="provider"
            value={code}
            checked={value === code}
            onChange={() => onChange(code)}
            {...blockedRadio(locked)}
            className="size-5 accent-foreground"
          />
          {name}
        </Label>
      ))}
      {error ? (
        <p id={PROVIDER_ERROR_ID} role={announced ? "alert" : undefined} className="text-sm text-destructive">
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
  locked,
  onChange,
}: {
  options: DeliveryOption[];
  value: string | null;
  error: string | null;
  currency: string;
  /** While the order is placed the choice does not change. */
  locked: boolean;
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
        <Label
          key={option.methodId}
          htmlFor={`${DELIVERY_ID_PREFIX}${option.methodId}`}
          className={cn("min-h-11 items-start gap-3 py-1 text-ui font-normal", locked && BLOCKED_CHOICE)}
        >
          <input
            id={`${DELIVERY_ID_PREFIX}${option.methodId}`}
            type="radio"
            name="deliveryMethod"
            required
            value={option.methodId}
            checked={value === option.methodId}
            onChange={() => onChange(option.methodId)}
            {...blockedRadio(locked)}
            className="mt-0.5 size-5 shrink-0 accent-foreground"
          />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="flex flex-wrap justify-between gap-x-4">
              <span className="font-semibold wrap-anywhere">{option.name}</span>
              <span>{option.costGross === 0 ? "Darmowa" : formatPrice(option.costGross, currency)}</span>
            </span>
            {option.description ? <span className="text-sm text-muted-foreground wrap-anywhere">{option.description}</span> : null}
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
  announced,
  locked,
  onChange,
}: {
  name: FieldName;
  label: string;
  type: string;
  autoComplete: string;
  value: string;
  error?: string;
  /** Whether the error is an alert (see `errorsAnnounced`). */
  announced: boolean;
  /** While the order is placed the field keeps its value: read-only with `aria-disabled`, and it keeps the focus. */
  locked: boolean;
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
        readOnly={locked}
        aria-disabled={locked ? true : undefined}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={BLOCKED_FIELD}
        onChange={(event) => onChange(name, event.target.value)}
      />
      {error ? (
        <p id={errorId} role={announced ? "alert" : undefined} className="text-sm text-destructive">
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
  locked,
  onInput,
  onApply,
  onRemove,
}: {
  state: CodeState;
  /** While the order is placed the field keeps its value and the buttons send nothing. */
  locked: boolean;
  onInput: (value: string) => void;
  onApply: (event: FormEvent<HTMLFormElement>) => void;
  onRemove: () => void;
}) {
  // A running request (the order or a check of the code) blocks the buttons with `aria-disabled`, not `disabled`, which
  // would drop the keyboard focus; the handlers send nothing meanwhile.
  const buttonsBlocked = locked || state.busy ? true : undefined;
  return (
    <div className="flex flex-col gap-2">
      <div data-testid="checkout-code-status" role="status" className="sr-only">
        {state.status}
      </div>
      {state.applied ? (
        <Button id={CODE_REMOVE_ID} type="button" variant="outline" className={cn("self-start", BLOCKED_BUTTON)} aria-disabled={buttonsBlocked} onClick={onRemove}>
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
              readOnly={locked}
              aria-disabled={locked ? true : undefined}
              aria-invalid={state.error ? true : undefined}
              aria-describedby={state.error ? CODE_ERROR_ID : undefined}
              className={BLOCKED_FIELD}
              onChange={(event) => onInput(event.target.value)}
            />
            <Button type="submit" variant="outline" className={BLOCKED_BUTTON} aria-disabled={buttonsBlocked} aria-busy={state.busy || undefined}>
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
                {/* A name or a label of the data can be one word longer than the screen: `wrap-anywhere` breaks it. */}
                <div className="flex min-w-0 flex-col wrap-anywhere">
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
                  <span data-testid="checkout-line-total" className="shrink-0 font-semibold">
                    {formatPrice(line.lineTotal.gross, line.lineTotal.currency)}
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
          {cart.discount?.status === "applied" ? (
            <p data-testid="checkout-discount" className="flex justify-between text-sm wrap-anywhere">
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
