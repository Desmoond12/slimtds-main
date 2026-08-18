<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PostbackRequestsLog extends AbstractMigration
{
    public function up(): void
    {
        // Raw incoming-postback audit trail — every /postback hit is logged
        // here regardless of outcome (accepted, rejected, duplicate), before
        // any business decision. core.conversions only ever holds the
        // *current* parsed state for a click; this table is the append-only
        // "what actually arrived and what we did with it" record needed to
        // resolve partner disputes (partner claims they sent an FTD postback,
        // operator needs to prove whether it ever hit the endpoint and why
        // it was accepted/rejected) without grepping raw web server logs.
        $this->execute(<<<'SQL'
            CREATE TABLE core.postback_requests (
                id                 bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                received_at        timestamptz NOT NULL DEFAULT now(),
                method             text        NOT NULL,
                raw_query          text,
                source_ip          inet,
                parsed_subid       text,
                parsed_token       text,
                parsed_status      text,
                parsed_payout      text,
                parsed_external_id text,
                conversion_id      uuid REFERENCES core.conversions(id) ON DELETE SET NULL,
                offer_id           uuid REFERENCES core.offers(id) ON DELETE SET NULL,
                processing_status  text        NOT NULL,
                http_status        smallint    NOT NULL
            )
        SQL);
        $this->execute('CREATE INDEX idx_postback_requests_received ON core.postback_requests (received_at DESC)');
        $this->execute(
            'CREATE INDEX idx_postback_requests_conversion ON core.postback_requests (conversion_id) WHERE conversion_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.postback_requests');
    }
}
