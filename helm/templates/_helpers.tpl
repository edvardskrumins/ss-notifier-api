{{/*
App name.
*/}}
{{- define "ss-notifier-api.name" -}}
{{- .Chart.Name }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "ss-notifier-api.labels" -}}
helm.sh/chart: {{ include "ss-notifier-api.name" . }}-{{ .Chart.Version | replace "+" "_" }}
{{ include "ss-notifier-api.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "ss-notifier-api.selectorLabels" -}}
app.kubernetes.io/name: {{ include "ss-notifier-api.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
PHP image
*/}}
{{- define "ss-notifier-api.phpImage" -}}
{{- printf "%s/%s/%s:%s" .Values.imageRegistry .Values.imageRepository .Values.php.image.repository .Values.php.image.tag }}
{{- end }}

{{/*
Nginx image
*/}}
{{- define "ss-notifier-api.nginxImage" -}}
{{- printf "%s/%s/%s:%s" .Values.imageRegistry .Values.imageRepository .Values.nginx.image.repository .Values.nginx.image.tag }}
{{- end }}

{{/*
Worker image
*/}}
{{- define "ss-notifier-api.workerImage" -}}
{{- printf "%s/%s/%s:%s" .Values.imageRegistry .Values.imageRepository .Values.worker.image.repository .Values.worker.image.tag }}
{{- end }}

