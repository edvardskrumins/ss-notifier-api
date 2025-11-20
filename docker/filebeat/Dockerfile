FROM docker.elastic.co/beats/filebeat:9.1.4

COPY docker/filebeat/filebeat.yml /usr/share/filebeat/filebeat.yml

USER root
RUN chown root:filebeat /usr/share/filebeat/filebeat.yml && \
    chmod 600 /usr/share/filebeat/filebeat.yml

USER filebeat

