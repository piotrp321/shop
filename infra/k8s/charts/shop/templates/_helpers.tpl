{{/*
Helpers of the chart. Arguments are passed as a dict with `root` (the top-level context, `$`) and the parameters named
in the comment of each helper.
*/}}

{{/* Host of a service: <sub>.<domain>, or the domain itself when sub is empty. Parameters: root, sub. */}}
{{- define "shop.host" -}}
{{- if .sub }}{{ .sub }}.{{ .root.Values.global.domain }}{{ else }}{{ .root.Values.global.domain }}{{ end -}}
{{- end }}

{{/* Public (browser-facing) URL of a host. Parameters: root, sub. */}}
{{- define "shop.url" -}}
{{ .root.Values.global.scheme }}://{{ include "shop.host" . }}
{{- end }}

{{/*
Image built by us: <registry>/<app>:<tag>, and @<digest> when image.digests has one (the image is then pulled by the
digest). Parameters: root, app (api, store, admin, elasticsearch).
*/}}
{{- define "shop.appImage" -}}
{{- $digest := index .root.Values.image.digests .app -}}
{{ .root.Values.image.registry }}/{{ .app }}:{{ required "image.tag (the revision) is required" .root.Values.image.tag }}{{ if $digest }}@{{ $digest }}{{ end }}
{{- end }}

{{/* Labels of a resource. Parameters: root, name (the logical name of the service or job). */}}
{{- define "shop.labels" -}}
app.kubernetes.io/name: {{ .name }}
app.kubernetes.io/instance: {{ .root.Release.Name }}
app.kubernetes.io/part-of: shop
{{- end }}

{{/* Labels that select the pods of a service. Parameters: root, name. */}}
{{- define "shop.selector" -}}
app.kubernetes.io/name: {{ .name }}
app.kubernetes.io/instance: {{ .root.Release.Name }}
{{- end }}

{{/* Argo CD sync wave. The argument is the wave as a string. */}}
{{- define "shop.wave" -}}
argocd.argoproj.io/sync-wave: {{ . | quote }}
{{- end }}

{{/* The resources of a service. Parameters: root, resources (the key of .Values.resources). */}}
{{- define "shop.resources" -}}
{{ toYaml (index .root.Values.resources .resources) }}
{{- end }}

{{/* The environment of the containers that read the secret (as env_file does in the compose file). */}}
{{- define "shop.envFromSecret" -}}
envFrom:
  - secretRef:
      name: {{ .Values.secrets.name }}
{{- end }}

{{/* MAILER_FROM. */}}
{{- define "shop.mailerFrom" -}}
{{- if .Values.mailer.from }}{{ .Values.mailer.from }}{{ else }}Rytm <zamowienia@{{ .Values.global.domain }}>{{ end -}}
{{- end }}

{{/*
An initContainer that waits until a shell condition holds. It is named wait-for-<name>, where <name> is the logical
name of what it waits for: `make check` reads these names to verify that every dependency is in an earlier wave.
Parameters: root, name, and check (a shell condition) or script (a shell function body that returns non-zero until
ready); optional: secret (true: the container reads the secret). Every connection in a check has a timeout
(nc -w, wget -T): without one, the first attempt at a service whose pod is not there yet hangs for minutes.
*/}}
{{- define "shop.wait" -}}
- name: wait-for-{{ .name }}
  image: {{ .root.Values.images.alpine }}
  command: ["sh", "-c"]
  args:
    - |
      {{- if .script }}
      check() {
        {{- .script | nindent 8 }}
      }
      until check; do
      {{- else }}
      until {{ .check }}; do
      {{- end }}
        echo "waiting for {{ .name }}"
        sleep 2
      done
  {{- if .secret }}
  {{- include "shop.envFromSecret" .root | nindent 2 }}
  {{- end }}
  resources:
    {{- include "shop.resources" (dict "root" .root "resources" "job") | nindent 4 }}
{{- end }}

{{/*
An initContainer that waits for the rollout of a Deployment of this release (kubectl rollout status), so that the
order also holds when the chart is installed without sync waves (D8) and when a Service is still answered by an old pod.
It is the only kind of container that gets a Kubernetes API token (see shop.kubeApiAccessVolume).
Parameters: root, name (the Deployment).
*/}}
{{- define "shop.waitRollout" -}}
- name: wait-for-{{ .name }}
  image: {{ .root.Values.images.kubectl }}
  args: ["rollout", "status", "deployment/{{ .name }}", "--timeout=1500s"]
  volumeMounts:
    - name: kube-api-access
      mountPath: /var/run/secrets/kubernetes.io/serviceaccount
      readOnly: true
  resources:
    {{- include "shop.resources" (dict "root" .root "resources" "job") | nindent 4 }}
{{- end }}

{{/* The volume of the token for the containers of shop.waitRollout: the pods do not get a token automatically. */}}
{{- define "shop.kubeApiAccessVolume" -}}
- name: kube-api-access
  projected:
    defaultMode: 0444
    sources:
      - serviceAccountToken:
          expirationSeconds: 3607
          path: token
      - configMap:
          name: kube-root-ca.crt
          items:
            - key: ca.crt
              path: ca.crt
      - downwardAPI:
          items:
            - path: namespace
              fieldRef:
                fieldPath: metadata.namespace
{{- end }}

{{/*
A ClusterIP service. Parameters: root, name, ports (a list of dicts with name, port and optional targetPort); optional:
publishNotReady (true: the service routes to the pod before it is ready, for a service whose own probe connects to it).
*/}}
{{- define "shop.service" -}}
apiVersion: v1
kind: Service
metadata:
  name: {{ .name }}
  labels:
    {{- include "shop.labels" . | nindent 4 }}
  annotations:
    {{- include "shop.wave" "-2" | nindent 4 }}
spec:
  {{- if .publishNotReady }}
  publishNotReadyAddresses: true
  {{- end }}
  selector:
    {{- include "shop.selector" . | nindent 4 }}
  ports:
    {{- range .ports }}
    - name: {{ .name }}
      port: {{ .port }}
      targetPort: {{ .targetPort | default .port }}
    {{- end }}
{{- end }}

{{/*
A Deployment with one replica that never loses capacity during an update (maxUnavailable 0): a new pod that does not
become ready leaves the old one serving.
Parameters: root, name, wave, resources, container (YAML of the container without name, image and resources),
image; optional: initContainers, volumes (YAML lists), podAnnotations (YAML map).
*/}}
{{- define "shop.deployment" -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ .name }}
  labels:
    {{- include "shop.labels" . | nindent 4 }}
  annotations:
    {{- include "shop.wave" .wave | nindent 4 }}
spec:
  replicas: 1
  revisionHistoryLimit: 2
  # At least as long as the jobs wait for the rollout (kubectl rollout status in shop.waitRollout stops at the deadline).
  progressDeadlineSeconds: 1500
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxUnavailable: 0
      maxSurge: 1
  selector:
    matchLabels:
      {{- include "shop.selector" . | nindent 6 }}
  template:
    metadata:
      labels:
        {{- include "shop.labels" . | nindent 8 }}
      {{- with .podAnnotations }}
      annotations:
        {{- toYaml . | nindent 8 }}
      {{- end }}
    spec:
      # The variables of the services (API_PORT, STORE_PORT, ...) would collide with the settings of the applications.
      enableServiceLinks: false
      automountServiceAccountToken: false
      {{- if .initContainers }}
      initContainers:
        {{- .initContainers | nindent 8 }}
      {{- end }}
      containers:
        - name: {{ .name }}
          image: {{ .image }}
          {{- .container | nindent 10 }}
          resources:
            {{- include "shop.resources" (dict "root" .root "resources" .name) | nindent 12 }}
      {{- if .volumes }}
      volumes:
        {{- .volumes | nindent 8 }}
      {{- end }}
{{- end }}

{{/*
A StatefulSet with one replica and volumes on the local-path storage class.
Parameters: root, name, wave, image, container, claims (a list of dicts with name and size); optional: initContainers,
volumes, podAnnotations, serviceName.
*/}}
{{- define "shop.statefulset" -}}
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: {{ .name }}
  labels:
    {{- include "shop.labels" . | nindent 4 }}
  annotations:
    {{- include "shop.wave" .wave | nindent 4 }}
spec:
  replicas: 1
  serviceName: {{ .name }}
  selector:
    matchLabels:
      {{- include "shop.selector" . | nindent 6 }}
  template:
    metadata:
      labels:
        {{- include "shop.labels" . | nindent 8 }}
      {{- with .podAnnotations }}
      annotations:
        {{- toYaml . | nindent 8 }}
      {{- end }}
    spec:
      enableServiceLinks: false
      automountServiceAccountToken: false
      {{- if .initContainers }}
      initContainers:
        {{- .initContainers | nindent 8 }}
      {{- end }}
      containers:
        - name: {{ .name }}
          image: {{ .image }}
          {{- .container | nindent 10 }}
          resources:
            {{- include "shop.resources" (dict "root" .root "resources" .name) | nindent 12 }}
      {{- if .volumes }}
      volumes:
        {{- .volumes | nindent 8 }}
      {{- end }}
  volumeClaimTemplates:
    {{- range .claims }}
    - metadata:
        name: {{ .name }}
      spec:
        accessModes: ["ReadWriteOnce"]
        storageClassName: {{ $.root.Values.storage.className }}
        resources:
          requests:
            storage: {{ .size }}
    {{- end }}
{{- end }}

{{/*
A Job of a version (D11): the name is <job>-<checksum of the pod template and of the settings of the Job>, so a new
revision or a changed configuration of the Job gives a new Job and the same state does not run it again. No
ttlSecondsAfterFinished: the finished Job stays; `make -C infra/k8s rerun JOB=<name>` runs a failed one again.
Parameters: root, name, wave, template (YAML of the pod template: metadata and spec).
*/}}
{{- define "shop.job" -}}
{{- $settings := dict "backoffLimit" .root.Values.jobs.backoffLimit "activeDeadlineSeconds" .root.Values.jobs.activeDeadlineSeconds -}}
apiVersion: batch/v1
kind: Job
metadata:
  name: {{ .name }}-{{ printf "%s%s" .template (toJson $settings) | sha256sum | trunc 10 }}
  labels:
    {{- include "shop.labels" . | nindent 4 }}
    # Tells the jobs of a version from the runs of the cron job.
    shop/job-kind: version
  annotations:
    {{- include "shop.wave" .wave | nindent 4 }}
spec:
  backoffLimit: {{ .root.Values.jobs.backoffLimit }}
  activeDeadlineSeconds: {{ .root.Values.jobs.activeDeadlineSeconds }}
  template:
    {{- .template | nindent 4 }}
{{- end }}
