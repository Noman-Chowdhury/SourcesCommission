<?php

namespace Sources\AffiliateCommission;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sources\AffiliateCommission\Contract\makeRelation;
use Sources\AffiliateCommission\Models\Referrer;

trait CommissionTrait
{
    /**
     * @param $banner_id
     * @return Builder|mixed
     */
    public function getBannerData($banner_id)
    {
        return DB::connection('affiliate')->table('banners')->find($banner_id);
    }

    /**
     * @param $offer_id
     * @return mixed
     */
    public function findBannerOfferCommission($offer_id)
    {
        return DB::connection('affiliate')->table('commission_offers')->find($offer_id)->commission;
    }

    /**
     * @param $commission_type
     * @return Model|Builder|object
     */
    public function getCommissionData($commission_type)
    {
        return DB::connection('affiliate')->table('commissions')->where(['type' => $commission_type])->first()->commission;
    }

    /**
     * @param $referral_code
     * @return Model|Builder|object
     */
    public function getAffiliateUserData($referral_code)
    {
        return DB::connection('affiliate')->table('users')->where('referral_code', $referral_code)->first();
    }

    /**
     * @param $user_id
     * @param bool $increment
     * @param int $amount
     * @return bool|Model|Builder
     */
    public function findOrCreateAffiliateUserWallet($user_id, bool $increment = true, int $amount = 0)
    {
        try {
            $wallet = DB::connection('affiliate')->table('wallets')->where('user_id', $user_id)->first();
            if (!$wallet) {
                $wallet = DB::connection('affiliate')->table('wallets')->insert([
                    'user_id' => $user_id,
                    'balance' => 0,
                    'other_balance' => 0,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now(),
                ]);
            }
            if ($increment) {
                $this->incrementWalletBalance($user_id, $wallet, $amount);
            }
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
        return $wallet;
    }

    /**
     * @param $user_id
     * @param $wallet
     * @param $amount
     * @return void
     */
    public function incrementWalletBalance($user_id, $wallet, $amount): void
    {
        try {
            DB::connection('affiliate')->table('wallets')->where('user_id', $user_id)->limit(1)->update([
                'balance' => $wallet->balance + $amount
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
    }

    /**
     * @param $user_id
     * @param $commission_type
     * @param $banner
     * @param $extra_info
     * @return void
     * @throws \JsonException
     */
    public function insertCommissionInfo($user_id, $commission_type, $banner = null, $extra_info = null): void
    {
        try{
            DB::connection('affiliate')->table('aff_commission_histories')->insert([
                'user_id' => $user_id,
                'from_id' => $banner ? $banner->id : null,
                'from_type' => $banner ? 'banner' : null,
                'commission' => $this->getCommissionData($commission_type),
                'type' => $commission_type,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'information' => json_encode($extra_info, JSON_THROW_ON_ERROR),
            ]);
        }catch (\Exception $exception){
            Log::error($exception->getMessage());
        }
        $this->findOrCreateAffiliateUserWallet($user_id, true, ($banner && $banner->offer_id ? $this->findBannerOfferCommission($banner->offer_id) : $this->getCommissionData($commission_type)));

    }

    /**
     * @param $commission_type
     * @param $user_type
     * @param null $user
     * @return void
     * @throws \JsonException
     */
    public function bannerRegistrationCommission($commission_type, $user_type, $user = null): void
    {
        $user = request()->user() ?? $user;
        if ($user->referred) {
            $affiliate_user = $this->getAffiliateUserData($user->referred->referrer);
            $domain = DB::connection('affiliate')->table('user_sites')->where('code', $user->referred->host)->first();
            if ($domain && $domain->status === 'approved' && $affiliate_user && $domain->verified) {
                $banner_id = json_decode($user->referred->info, true, 512, JSON_THROW_ON_ERROR)['banner_id'];
                $extra_info = ['name' => $user->name, 'user_type' => $user_type];
                $this->insertCommissionInfo($affiliate_user->id, $commission_type, $this->getBannerData($banner_id), $extra_info);
            }
        }
    }

    /**
     * @param $commission_type
     * @param $user_type
     * @param null $user
     * @return void
     * @throws \JsonException
     */
    public function directRegistrationCommission($commission_type, $user_type, $user = null): void
    {
        $user = request()->user() ?? $user;
        if ($user->referred) {
            $affiliate_user = $this->getAffiliateUserData($user->referred->referrer);
            if ($affiliate_user) {
                $extra_info = ['name' => $user->name, 'user_type' => $user_type];
                $this->insertCommissionInfo($affiliate_user->id, $commission_type, null, $extra_info);
            }
        }
    }


    /**
     * @throws \JsonException
     */
    public function storeReferrerData($userData): void
    {
        try {
            $commission = null;
            $info = null;
            $referrer = null;
            if (request()->b !== 'null') {
                $banner = $this->getBannerData(base64_decode(request()->b));
                if ($banner->offer_id) {
                    $offer = $this->findBannerOfferCommission($banner->offer_id);
                    $commission = $offer->user_commission;
                }
                $info = json_encode([
                    'banner_id' => $banner->id,
                ], JSON_THROW_ON_ERROR);
            }
            if (request()->u !== 'null') {
                $referrer = request()->u;
            } else {
                if (request()->referrer_code !== 'null') {
                    $referrer = request()->referrer_code;
                }
            }
            Models\Referrer::create([
                'user_id' => $userData->id,
                'user_type' => get_class($userData),
                'referrer' => $referrer,
                'host' => request()->h ?? null,
                'commission' => $commission,
                'info' => $info
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
    }

    public function referred()
    {
        return self::morphOne(Referrer::class, 'referred', 'user_type', 'user_id');
    }

    /**
     */
    public function giveCommission($userData, $user_type): void
    {
        try {
            $type = $userData->referred->info !== NULL ? 'banner' : 'direct';
            $commission_type = $user_type . '-' . $type . '-registration';
            $userData->referred->info !== NULL ? $this->bannerRegistrationCommission($commission_type, $user_type) : $this->directRegistrationCommission($commission_type, $user_type);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
    }

}
