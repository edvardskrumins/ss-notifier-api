```
docker compose exec php php artisan key:generate
```


## Database access from pgAdmin
```
kubectl port-forward -n ss-notifier svc/ss-notifier-api-postgresql 5432:5432
```

## Longhorn (https://longhorn.io/docs/1.10.1/deploy/install/)
```
apt-get update && sudo apt-get install -y nfs-common
```