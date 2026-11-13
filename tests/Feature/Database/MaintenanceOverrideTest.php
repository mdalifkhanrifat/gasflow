<?php

/**
 * Backend maintenance override (php artisan gasflow:maintenance): a deliberate,
 * logged manual correction on the separate operator connection, previewed and
 * committed only after a typed confirmation.
 *
 * The command's transaction runs as a savepoint inside the test transaction
 * opened on pgsql_maintenance here, so nothing is left behind.
 */

use App\Shared\Lifecycle\Maintenance\MaintenanceOverrideCommand;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

const CONFIRM_QUESTION = 'Type APPLY to commit these changes';
const AUDIT_REASON = 'Audit request #17: duplicate entry';

function maintenance(): Connection
{
    return DB::connection(MaintenanceOverrideCommand::CONNECTION);
}

function ledgerRow(float $amount = 100): int
{
    return (int) maintenance()->table('lifecycle_test_ledgers')->insertGetId(['amount' => $amount]);
}

function ledgerAmount(int $id): string
{
    return (string) maintenance()->table('lifecycle_test_ledgers')->where('id', $id)->value('amount');
}

/**
 * @param  list<string>  $sql
 * @param  array<string, mixed>  $options
 */
function overrideCommand(array $sql, array $options = []): PendingCommand
{
    return test()->artisan('gasflow:maintenance', ['--sql' => $sql, '--reason' => AUDIT_REASON, ...$options]);
}

beforeEach(function () {
    maintenance()->beginTransaction();
});

afterEach(function () {
    while (maintenance()->transactionLevel() > 0) {
        maintenance()->rollBack();
    }
});

describe('connection', function () {
    it('refuses to run when the maintenance connection is not configured', function () {
        // Arrange
        config(['database.connections.pgsql_maintenance.username' => '']);

        // Act & Assert
        overrideCommand(['update lifecycle_test_ledgers set amount = 1'])
            ->expectsOutputToContain('connection is not configured on this machine')
            ->assertFailed();
    });

    it('refuses a database role outside gasflow_maintenance', function () {
        // Arrange
        config([
            'database.connections.pgsql_maintenance.username' => 'gasflow_app',
            'database.connections.pgsql_maintenance.password' => config('database.connections.pgsql.password'),
        ]);
        DB::purge(MaintenanceOverrideCommand::CONNECTION);

        // Act & Assert
        overrideCommand(['update lifecycle_test_ledgers set amount = 1'])
            ->expectsOutputToContain('The role [gasflow_app] is not a member of gasflow_maintenance')
            ->assertFailed();
    });
});

describe('input', function () {
    it('needs at least one statement', function () {
        // Act & Assert
        overrideCommand([])
            ->expectsOutputToContain('Give at least one statement with --sql')
            ->assertFailed();
    });

    it('refuses statements that do not change rows', function () {
        // Act & Assert
        overrideCommand(['commit'])
            ->expectsOutputToContain('Only INSERT, UPDATE, DELETE or WITH statements are allowed')
            ->assertFailed();
    });

    it('looks past leading comments for the real command', function () {
        // Act & Assert
        overrideCommand(["-- update\n/* delete */ set app.maintenance_override = 'off'"])
            ->expectsOutputToContain('Only INSERT, UPDATE, DELETE or WITH statements are allowed')
            ->assertFailed();
    });

    it('refuses a reason shorter than ten characters', function () {
        // Act & Assert
        overrideCommand(['update lifecycle_test_ledgers set amount = 1'], ['--reason' => 'fix'])
            ->expectsOutputToContain('The reason must explain the change in at least 10 characters')
            ->assertFailed();
    });

    it('asks for the reason when it is not given', function () {
        // Arrange
        $id = ledgerRow();

        // Act & Assert
        test()->artisan('gasflow:maintenance', ['--sql' => ["update lifecycle_test_ledgers set amount = 90 where id = {$id}"], '--dry-run' => true])
            ->expectsQuestion('Why is this change made? (recorded with every changed row)', AUDIT_REASON)
            ->expectsOutputToContain('Dry run: rolled back')
            ->assertSuccessful();
    });

    it('refuses an actor that is not a user id', function () {
        // Act & Assert
        overrideCommand(['update lifecycle_test_ledgers set amount = 1'], ['--user' => '7 or 1=1'])
            ->expectsOutputToContain('--user must be a users.id')
            ->assertFailed();
    });
});

describe('preview', function () {
    it('lists every changed row with its changed columns before asking', function () {
        // Arrange
        $first = ledgerRow();
        $second = ledgerRow();

        // Act & Assert
        overrideCommand(["update lifecycle_test_ledgers set amount = amount - 10 where id in ({$first}, {$second})"], ['--dry-run' => true])
            ->expectsOutputToContain('2 rows reported')
            ->expectsTable(['Table', 'Record', 'Operation', 'Changed columns'], [
                ['lifecycle_test_ledgers', (string) $first, 'update', 'amount'],
                ['lifecycle_test_ledgers', (string) $second, 'update', 'amount'],
            ])
            ->assertSuccessful();
    });

    it('says so when only tables outside the lifecycle standard changed', function () {
        // Act & Assert
        overrideCommand(["insert into lifecycle_test_entries(reference) values ('JRN-2')"], ['--dry-run' => true])
            ->expectsOutputToContain('1 rows reported')
            ->expectsOutputToContain('No table under the lifecycle standard changed')
            ->assertSuccessful();
    });

    it('rolls back a dry run', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand(["update lifecycle_test_ledgers set amount = 90 where id = {$id}"], ['--dry-run' => true])
            ->assertSuccessful();

        // Assert
        expect(ledgerAmount($id))->toBe('100.00');
    });

    it('rolls back when nothing changed', function () {
        // Act & Assert
        overrideCommand(['update lifecycle_test_ledgers set amount = 1 where false'])
            ->expectsOutputToContain('Nothing changed, rolled back')
            ->assertFailed();
    });

    it('shows a rule that is checked only at commit before asking', function () {
        // Arrange
        maintenance()->table('lifecycle_test_entries')->insert(['reference' => 'JRN-1']);

        // Act & Assert
        overrideCommand(["insert into lifecycle_test_entries(reference) values ('JRN-1')"])
            ->expectsOutputToContain('uq_lifecycle_test_entries_reference')
            ->assertFailed();
    });
});

describe('confirmation', function () {
    it('rolls back when the confirmation is not typed exactly', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand(["update lifecycle_test_ledgers set amount = 90 where id = {$id}"])
            ->expectsQuestion(CONFIRM_QUESTION, 'apply')
            ->expectsOutputToContain('Not confirmed, rolled back')
            ->assertFailed();

        // Assert
        expect(ledgerAmount($id))->toBe('100.00');
    });

    it('never commits without an interactive confirmation', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand(["update lifecycle_test_ledgers set amount = 90 where id = {$id}"], ['--no-interaction' => true])
            ->expectsOutputToContain('A person must confirm the change')
            ->assertFailed();

        // Assert
        expect(ledgerAmount($id))->toBe('100.00');
    });
});

describe('applying', function () {
    it('hides a protected money row after the typed confirmation', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand(["delete from lifecycle_test_ledgers where id = {$id}"])
            ->expectsQuestion(CONFIRM_QUESTION, 'APPLY')
            ->assertSuccessful();

        // Assert
        $row = maintenance()->table('lifecycle_test_ledgers')->where('id', $id)->first();
        expect($row->is_deleted)->toBeTrue()
            ->and($row->deleted_at)->not->toBeNull();
    });

    it('records the reason, the operator role and the actor in the override log', function () {
        // Arrange
        $id = ledgerRow();
        $operator = (int) maintenance()->table('users')->insertGetId([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'not-a-real-hash',
        ]);

        // Act
        overrideCommand(["delete from lifecycle_test_ledgers where id = {$id}"], ['--user' => (string) $operator])
            ->expectsQuestion(CONFIRM_QUESTION, 'APPLY')
            ->assertSuccessful();

        // Assert
        $log = maintenance()->table('maintenance_override_logs')
            ->where('entity_name', 'lifecycle_test_ledgers')
            ->where('record_key', (string) $id)
            ->sole();
        expect($log->operation)->toBe('hide')
            ->and($log->reason)->toBe(AUDIT_REASON)
            ->and($log->db_role)->toBe('gasflow_operator')
            ->and($log->actor_user_id)->toBe($operator);
    });

    it('marks the activity trail as written under the override from the console', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand(["update lifecycle_test_ledgers set amount = 90 where id = {$id}"])
            ->expectsQuestion(CONFIRM_QUESTION, 'APPLY')
            ->assertSuccessful();

        // Assert
        $trail = maintenance()->table('data_change_logs')
            ->where('entity_name', 'lifecycle_test_ledgers')
            ->where('record_key', (string) $id)
            ->where('operation', 'update')
            ->sole();
        expect($trail->via_override)->toBeTrue()
            ->and($trail->client_type)->toBe('console')
            ->and($trail->reason)->toBe(AUDIT_REASON);
    });

    it('rolls back every statement when the database refuses one', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand([
            "update lifecycle_test_ledgers set amount = 90 where id = {$id}",
            "update maintenance_override_logs set reason = 'rewritten'",
        ])
            ->expectsOutputToContain('The database refused the change, nothing was applied')
            ->assertFailed();

        // Assert
        expect(ledgerAmount($id))->toBe('100.00');
    });

    it('refuses several statements in one option', function () {
        // Arrange
        $id = ledgerRow();

        // Act
        overrideCommand(["update lifecycle_test_ledgers set amount = 90 where id = {$id}; commit"])
            ->expectsOutputToContain('The database refused the change')
            ->assertFailed();

        // Assert
        expect(ledgerAmount($id))->toBe('100.00');
    });
});
