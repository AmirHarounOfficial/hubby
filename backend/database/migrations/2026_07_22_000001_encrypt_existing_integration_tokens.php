<?php

use App\Models\Integration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill: encrypt the OAuth tokens already sitting in `integrations` in plaintext.
 *
 * App\Models\Integration now casts `access_token` / `refresh_token` as `encrypted`,
 * which only covers rows written after that change. Rows stored before it are still
 * plaintext and would blow up on read (DecryptException), so they are rewritten here.
 *
 * Rows are always read raw through the query builder — bypassing the cast, so we see
 * what is actually on disk. Every value is probed with Crypt before being touched,
 * which makes this safe to re-run: already-encrypted rows are skipped, and a fresh
 * database simply has nothing to do.
 */
return new class extends Migration
{
    private const COLUMNS = ['access_token', 'refresh_token'];

    public function up(): void
    {
        $this->rewrite(
            // Plaintext values pass through; anything that already decrypts is skipped.
            resolve: fn (string $value) => $this->isEncrypted($value) ? null : $value,
            // Written through the model so the `encrypted` cast does the encrypting.
            write: function (int $id, array $updates) {
                // Deliberately NOT Integration::find(): a hydrated model keeps the
                // plaintext token in $original, and Eloquent's dirty check runs
                // castAttribute() over $original for `encrypted` casts (it is listed
                // in $primitiveCastTypes). Decrypting plaintext throws, so loading
                // the row would blow up on exactly the rows we are here to fix.
                //
                // Instead: an empty model whose only known attribute is the key.
                // access_token/refresh_token are absent from $original, so they read
                // as dirty and the cast encrypts them on the way out.
                $integration = new Integration();
                $integration->setRawAttributes(['id' => $id], sync: true);
                $integration->exists = true;

                // A storage-format change is not a business event — keep updated_at as-is.
                $integration->timestamps = false;
                $integration->forceFill($updates)->save();
            },
        );
    }

    /**
     * Reverse the backfill so a rollback leaves the table readable by pre-cast code.
     */
    public function down(): void
    {
        $this->rewrite(
            resolve: function (string $value) {
                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    return null; // Already plaintext.
                }
            },
            // Deliberately NOT through the model: the cast is still on it at rollback
            // time and would immediately re-encrypt what we just decrypted.
            write: fn (int $id, array $updates) => DB::table('integrations')
                ->where('id', $id)
                ->update($updates),
        );
    }

    /**
     * Walk every integration row, handing each stored token value to $resolve.
     * A non-null return is collected and handed to $write; null means "leave it alone".
     */
    private function rewrite(callable $resolve, callable $write): void
    {
        if (! Schema::hasTable('integrations')) {
            return;
        }

        $columns = array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => Schema::hasColumn('integrations', $column)
        ));

        if ($columns === []) {
            return;
        }

        DB::table('integrations')
            ->select(array_merge(['id'], $columns))
            ->chunkById(100, function ($rows) use ($columns, $resolve, $write) {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column};

                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        $resolved = $resolve($value);

                        if ($resolved !== null) {
                            $updates[$column] = $resolved;
                        }
                    }

                    if ($updates !== []) {
                        $write((int) $row->id, $updates);
                    }
                }
            });
    }

    /**
     * Only a value produced by this app's APP_KEY decrypts cleanly, so a successful
     * decrypt is a reliable "this row was already migrated" signal.
     */
    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
