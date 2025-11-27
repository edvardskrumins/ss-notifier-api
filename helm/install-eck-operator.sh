#!/bin/bash

# This is a one-time setup required before deploying Elasticsearch/Kibana with ECK

set -e

helm repo add elastic https://helm.elastic.co
helm repo update

helm upgrade --install eck-operator elastic/eck-operator \
    --version 3.2.0 \
    --namespace elastic-system \
    --create-namespace \
    --wait

echo "ECK operator installed successfully"

