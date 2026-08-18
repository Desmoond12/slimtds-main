<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ConversionStatusHistory extends AbstractMigration
{
    public function up(): void
    {
        // core.conversions is a current-state table — PostbackController's
        // ON CONFLICT (click_id) DO UPDATE overwrites status/payout on every
        // repostback. That's fine for "what's the state now" dashboards but
        // destroys the transition history (pending→approved→rejected), which
        // is the only way to explain "yesterday's approved total was X, why
        // is it Y today". A DB trigger (not app-code) guarantees every write
        // path — postback, future admin edits, seed fixtures — is captured,
        // and only inserts a row when status or payout actually changed, so
        // identical partner repostback retries don't spam the log.
        $this->execute(<<<'SQL'
            CREATE TABLE core.conversion_status_history (
                id            bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                conversion_id uuid        NOT NULL REFERENCES core.conversions(id) ON DELETE CASCADE,
                click_id      uuid,
                status        text        NOT NULL,
                payout        numeric(10,2) NOT NULL,
                external_id   text,
                changed_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute(
            'CREATE INDEX idx_conversion_status_history_conv ON core.conversion_status_history (conversion_id, changed_at)',
        );

        $this->execute(<<<'SQL'
            CREATE FUNCTION core.log_conversion_status_change() RETURNS trigger AS $BODY$
            BEGIN
                IF TG_OP = 'INSERT'
                   OR NEW.status IS DISTINCT FROM OLD.status
                   OR NEW.payout IS DISTINCT FROM OLD.payout THEN
                    INSERT INTO core.conversion_status_history
                        (conversion_id, click_id, status, payout, external_id)
                    VALUES
                        (NEW.id, NEW.click_id, NEW.status, NEW.payout, NEW.external_id);
                END IF;
                RETURN NEW;
            END;
            $BODY$ LANGUAGE plpgsql
        SQL);
        $this->execute(<<<'SQL'
            CREATE TRIGGER trg_conversion_status_history
            AFTER INSERT OR UPDATE ON core.conversions
            FOR EACH ROW EXECUTE FUNCTION core.log_conversion_status_change()
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_conversion_status_history ON core.conversions');
        $this->execute('DROP FUNCTION IF EXISTS core.log_conversion_status_change()');
        $this->execute('DROP TABLE IF EXISTS core.conversion_status_history');
    }
}
