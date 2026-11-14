<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A flat may only be occupied by a customer of an organization that may work
 * in the flat's building (design 02 §4, V7.15): the building's tenant, or a
 * gas vendor / manager with an active grant, which keeps its customers under
 * its own organization.
 *
 * Foreign keys ignore row-level security, so without this check a request
 * could link another tenant's customer to a flat by id.
 * The check runs when an occupancy is written; ending a grant later leaves
 * past occupancies in place (they are history).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_flat_occupancy_customer() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE customer_org BIGINT; flat_building BIGINT;
            BEGIN
              SELECT tenant_organization_id INTO customer_org FROM customers WHERE id = NEW.customer_id;
              SELECT building_id INTO flat_building FROM flats WHERE id = NEW.flat_id;
              -- A missing customer or flat is reported by its foreign key.
              IF customer_org IS NOT NULL AND flat_building IS NOT NULL
                 AND NOT organization_can_access_building(customer_org, flat_building) THEN
                RAISE EXCEPTION 'The customer belongs to an organization without access to this building';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_flat_occupancy_customer
            BEFORE INSERT OR UPDATE OF flat_id, customer_id ON flat_occupancies
            FOR EACH ROW EXECUTE FUNCTION validate_flat_occupancy_customer();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_flat_occupancy_customer ON flat_occupancies;
            DROP FUNCTION IF EXISTS validate_flat_occupancy_customer();
            SQL);
    }
};
