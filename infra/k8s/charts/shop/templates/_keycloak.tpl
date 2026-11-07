{{/*
The files of the job keycloak-config (D9): the data of the ConfigMap keycloak-realms, mounted at /realms. Per realm:

  <realm>.json              the realm file of the repository without the section `users` (the demo accounts must not
                            exist in the cluster) and with the addresses of this environment; the job creates the realm
                            from it, or adds what is missing to an existing one (partialImport, existing resources skipped)
  <realm>.settings.json     the values of the realm the job sets on every run: the SMTP of keycloak.mail; for shop also
                            the registration (customerAccounts) and the e-mail verification
  <realm>.client.<id>.json  per client of the realm file, set on every run: redirectUris, webOrigins and the post-logout
                            redirect URIs (the addresses of the domain) and, for the confidential client, its secret

${STORE_CLIENT_SECRET}, ${SMTP_USER} and ${SMTP_PASSWORD} stay placeholders: the job puts in the values of the secrets,
so no secret is in the ConfigMap. The argument is the top-level context.
*/}}
{{- define "shop.keycloak.realmsData" -}}
{{- $smtp := include "shop.keycloak.smtp" . | fromJson -}}
{{- range $realm := list "shop" "backoffice" }}
{{- $text := $.Files.Get (printf "files/realms/%s.json" $realm) }}
{{- $text = replace "${STORE_URL}" (include "shop.url" (dict "root" $ "sub" "")) $text }}
{{- $text = replace "${ADMIN_URL}" (include "shop.url" (dict "root" $ "sub" "admin")) $text }}
{{- $document := fromJson $text }}
{{- $_ := unset $document "users" }}
{{ $realm }}.json: |
  {{- toPrettyJson $document | nindent 2 }}
{{- $settings := dict "smtpServer" $smtp }}
{{- if eq $realm "shop" }}
{{- $_ := set $settings "registrationAllowed" $.Values.customerAccounts }}
{{- $_ := set $settings "verifyEmail" true }}
{{- end }}
{{ $realm }}.settings.json: |
  {{- toPrettyJson $settings | nindent 2 }}
{{- range $client := $document.clients | default list }}
{{- $values := dict "redirectUris" ($client.redirectUris | default list) "webOrigins" ($client.webOrigins | default list) }}
{{- if hasKey $client "secret" }}
{{- $_ := set $values "secret" $client.secret }}
{{- end }}
{{- $logout := index ($client.attributes | default dict) "post.logout.redirect.uris" }}
{{- if $logout }}
{{- $_ := set $values "attributes" (dict "post.logout.redirect.uris" $logout) }}
{{- end }}
{{ $realm }}.client.{{ $client.clientId }}.json: |
  {{- toPrettyJson $values | nindent 2 }}
{{- end }}
{{- end }}
{{- end }}

{{/*
The SMTP of the realms (smtpServer of a realm in Keycloak): the Mailpit of the cluster or Brevo SMTP with STARTTLS, from
keycloak.mailFrom (default konto@<domain>). JSON; the argument is the top-level context.
*/}}
{{- define "shop.keycloak.smtp" -}}
{{- $smtp := dict "from" (.Values.keycloak.mailFrom | default (printf "konto@%s" .Values.global.domain)) "fromDisplayName" .Values.keycloak.mailFromName "ssl" "false" -}}
{{- if eq .Values.keycloak.mail "brevo" -}}
{{- $_ := merge $smtp (dict "host" .Values.brevo.host "port" (toString .Values.brevo.port) "auth" "true" "starttls" "true" "user" "${SMTP_USER}" "password" "${SMTP_PASSWORD}") -}}
{{- else -}}
{{- $_ := merge $smtp (dict "host" "mailpit" "port" "1025" "auth" "false" "starttls" "false" "user" "" "password" "") -}}
{{- end -}}
{{- toJson $smtp -}}
{{- end }}
