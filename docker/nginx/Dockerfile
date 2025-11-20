FROM nginx:1.28.0-alpine

RUN rm -f /etc/nginx/conf.d/*.conf

COPY public /var/www/html/public

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]

