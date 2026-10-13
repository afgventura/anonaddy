<?php

namespace App\Models;

use App\Http\Resources\DomainResource;
use App\Traits\HasEncryptedAttributes;
use App\Traits\HasUuid;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class Domain extends Model
{
    use HasEncryptedAttributes;
    use HasFactory;
    use HasUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $encrypted = [
        'description',
        'from_name',
    ];

    protected $fillable = [
        'domain',
        'description',
        'from_name',
        'active',
        'catch_all',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'active' => 'boolean',
        'catch_all' => 'boolean',
        'default_recipient_id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'domain_verified_at' => 'datetime',
        'domain_mx_validated_at' => 'datetime',
        'domain_sending_verified_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        Domain::deleting(function ($domain) {
            $domain->aliases()->withTrashed()->forceDelete();
        });
    }

    /**
     * Set the domain's name.
     */
    protected function domain(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower($value),
        );
    }

    /**
     * Get the user for the custom domain.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all of the domains's aliases.
     */
    public function aliases()
    {
        return $this->morphMany(Alias::class, 'aliasable');
    }

    /**
     * Get the domains's default recipient.
     */
    public function defaultRecipient()
    {
        return $this->hasOne(Recipient::class, 'id', 'default_recipient_id');
    }

    /**
     * Set the domains's default recipient.
     */
    public function setDefaultRecipientAttribute($recipient)
    {
        $this->attributes['default_recipient_id'] = $recipient->id;
        $this->setRelation('defaultRecipient', $recipient);
    }

    /**
     * Deactivate the domain.
     */
    public function deactivate()
    {
        $this->update(['active' => false]);
    }

    /**
     * Activate the domain.
     */
    public function activate()
    {
        $this->update(['active' => true]);
    }

    /**
     * Disable catch-all for the domain.
     */
    public function disableCatchAll()
    {
        $this->update(['catch_all' => false]);
    }

    /**
     * Enable catch-all for the domain.
     */
    public function enableCatchAll()
    {
        $this->update(['catch_all' => true]);
    }

    /**
     * Determine if the domain is verified.
     *
     * @return bool
     */
    public function isVerified()
    {
        return ! is_null($this->domain_verified_at);
    }

    /**
     * Determine if the domain is verified for sending.
     *
     * @return bool
     */
    public function isVerifiedForSending()
    {
        return ! is_null($this->domain_sending_verified_at);
    }

    /**
     * Mark this domain as verified.
     *
     * @return bool
     */
    public function markDomainAsVerified()
    {
        return $this->forceFill([
            'domain_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Mark this domain as verified for sending.
     *
     * @return bool
     */
    public function markDomainAsVerifiedForSending()
    {
        return $this->forceFill([
            'domain_sending_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Mark this domain as having valid MX records.
     *
     * @return bool
     */
    public function markDomainAsValidMx()
    {
        return $this->forceFill([
            'domain_mx_validated_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Checks if the domain has the correct records.
     */
    public function checkVerification()
    {
        if (App::environment('testing')) {
            return true;
        }

        try {
            return collect(dns_get_record($this->domain.'.', DNS_TXT))
                ->contains(function ($r) {
                    return trim($r['txt']) === 'aa-verify='.sha1(config('anonaddy.secret').user()->id.user()->domains->count());
                });
        } catch (Exception $e) {
            Log::info('DNS Get TXT Error:', ['domain' => $this->domain, 'user' => $this->user?->username, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Checks if the domain has the correct MX records.
     */
    public function checkMxRecords()
    {
        if (App::environment('testing')) {
            return true;
        }

        try {
            $mx = collect(dns_get_record($this->domain.'.', DNS_MX))
                ->sortBy('pri')
                ->first();
        } catch (Exception $e) {
            Log::info('DNS Get MX Error:', ['domain' => $this->domain, 'user' => $this->user?->username, 'error' => $e->getMessage()]);

            // If an error occurs then do not unverify
            if (! is_null($this->domain_mx_validated_at)) {
                return true;
            }

            return false;
        }

        if (! isset($mx['target'])) {
            return false;
        }

        if ($mx['target'] !== config('anonaddy.hostname')) {
            return false;
        }

        $this->markDomainAsValidMx();

        return true;
    }

    /**
     * Checks if the domain has the correct records for sending.
     */
    public function checkVerificationForSending()
    {
        if (App::environment('testing')) {
            $this->markDomainAsVerifiedForSending();

            return response()->json([
                'success' => true,
                'message' => 'Records verified for sending.',
                'data' => new DomainResource($this->fresh()),
            ]);
        }

        try {
            $spf = collect(dns_get_record($this->domain.'.', DNS_TXT))
                ->contains(function ($r) {
                    return preg_match("/^(v=spf1).*(include:spf\.".config('anonaddy.domain').'|mx).*(-|~)all$/', $r['txt']);
                });
        } catch (Exception $e) {
            Log::info('DNS Get SPF Error:', ['domain' => $this->domain, 'user' => $this->user?->username, 'error' => $e->getMessage()]);

            $spf = null;
        }

        if (! $spf) {
            return response()->json([
                'success' => false,
                'message' => 'SPF record not found. This could be due to DNS caching, please try again later.',
                'data' => new DomainResource($this->fresh()),
            ]);
        }

        try {
            $dmarc = collect(dns_get_record('_dmarc.'.$this->domain.'.', DNS_TXT))
                ->contains(function ($r) {
                    return preg_match('/^(v=DMARC1).*(p=quarantine|reject).*/', $r['txt']);
                });
        } catch (Exception $e) {
            Log::info('DNS Get DMARC Error:', ['domain' => $this->domain, 'user' => $this->user?->username, 'error' => $e->getMessage()]);

            $dmarc = null;
        }

        if (! $dmarc) {
            return response()->json([
                'success' => false,
                'message' => 'DMARC record not found. This could be due to DNS caching, please try again later.',
                'data' => new DomainResource($this->fresh()),
            ]);
        }

        // ── GoVisa fork: accept a PER-DOMAIN DKIM TXT as well as upstream's CNAME ──
        // Marker asserted by infra/addy/bootstrap-startup.sh when it stages this
        // file: govisa_accepts_per_domain_dkim_txt
        //
        // Upstream only accepts a CNAME pointing at the instance's own DKIM
        // record. That shape forces every alias domain onto ONE shared keypair,
        // because a CNAME can only ever resolve to a single record.
        //
        // We deliberately publish a PER-DOMAIN key instead: the private half is
        // generated on the mail host and never leaves it (infra/addy/addy-dkim.sh,
        // whose header explains why it must never go in Secret Manager). The point
        // is blast radius — a leaked key compromises one domain rather than all
        // seven, and these domains carry the OTP rail our applicants trust.
        //
        // Without this, the daily `anonaddy:check-domains-sending-verification`
        // fails every domain we own, NULLs domain_sending_verified_at, and
        // ForwardEmail::build() then rewrites From: from the alias to
        // config('mail.from.address') — i.e. ops silently stops being able to
        // send as the case's alias.
        //
        // So accept EITHER shape:
        //   (a) upstream's CNAME to <selector>._domainkey.<anonaddy.domain>, or
        //   (b) a TXT at <selector>._domainkey.<domain> holding this domain's own
        //       public key.
        //
        // The TXT is validated the way addy-dkim.sh validates what it publishes:
        // DKIM1 shape, and a p= long enough to be a real RSA key. That script
        // refuses anything under 300 base64 characters because a truncated record
        // is worse than none — the alias domains run strict DMARC (adkim=s), so a
        // broken signature fails closed.
        try {
            $selector = config('anonaddy.dkim_selector');
            $dkimName = $selector.'._domainkey.'.$this->domain.'.';

            $dkim = collect(dns_get_record($dkimName, DNS_CNAME))
                ->contains(function ($r) use ($selector) {
                    return ($r['target'] ?? null) === $selector.'._domainkey.'.config('anonaddy.domain');
                });

            if (! $dkim) {
                $dkim = collect(dns_get_record($dkimName, DNS_TXT))
                    ->contains(function ($r) {
                        $txt = trim($r['txt'] ?? '');

                        if (! preg_match('/^v=DKIM1\s*;/i', $txt)) {
                            return false;
                        }

                        // Everything after `p=`. dns_get_record already joins the
                        // quoted chunks, but strip whitespace anyway because the
                        // presentation form wraps and a wrapped record must not be
                        // mistaken for a short one.
                        if (! preg_match('/\bp=([A-Za-z0-9+\/=\s]+)/', $txt, $m)) {
                            return false;
                        }

                        return strlen(preg_replace('/\s+/', '', $m[1])) > 300;
                    });
            }
        } catch (Exception $e) {
            Log::info('DNS Get DKIM Error:', ['domain' => $this->domain, 'user' => $this->user?->username, 'error' => $e->getMessage()]);

            $dkim = null;
        }

        if (! $dkim) {
            return response()->json([
                'success' => false,
                'message' => 'No usable DKIM record at '.config('anonaddy.dkim_selector').'._domainkey.'.$this->domain.' — expected either a CNAME to the instance record or a TXT holding this domain\'s own public key. This could be due to DNS caching, please try again later.',
                'data' => new DomainResource($this->fresh()),
            ]);
        }

        $this->markDomainAsVerifiedForSending();

        return response()->json([
            'success' => true,
            'message' => 'Records successfully verified.',
            'data' => new DomainResource($this->fresh()),
        ]);
    }
}
