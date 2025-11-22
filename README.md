```
docker compose exec php php artisan key:generate
```


## Database access from pgAdmin
```
kubectl port-forward -n ss-notifier svc/ss-notifier-api-postgresql 5432:5432
```