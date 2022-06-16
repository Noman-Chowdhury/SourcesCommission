<?php

namespace Sources\AffiliateCommission;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sources\AffiliateCommission\Models\Referrer;

/**
 *
 */
trait CommissionTrait
{

    public function isCommissionActive()
    {
        $data = DB::connection('affiliate')->table('settings')->find(1)->data;
        return json_decode($data, false)->affiliate_status && (json_decode($data, false)->affiliate_status || json_decode($data, false)->affiliate_status === 'true');
    }

    public function getBannerData($banner_id)
    {
        return DB::connection('affiliate')->table('banners')->find($banner_id);
    }

    public function findBannerOfferCommission($offer_id)
    {
        return DB::connection('affiliate')->table('commission_offers')->find($offer_id)->commission;
    }

    public function getCommissionData($commission_type)
    {
        return DB::connection('affiliate')->table('commissions')->where(['type' => $commission_type])->first()->commission ?? 0;
    }


    public function getAffiliateUserData($referral_code)
    {
        return DB::connection('affiliate')->table('users')->where('referral_code', 'dHmObjpge2')->first();
    }


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


    public function incrementWalletBalance($user_id, $wallet, $amount)
    {
        try {
            DB::connection('affiliate')->table('wallets')->where('user_id', $user_id)->limit(1)->update([
                'balance' => $wallet->balance + $amount
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
    }


    public function insertCommissionInfo($user_id, $commission_type, $banner = null, $extra_info = null)
    {
        try {
            DB::connection('affiliate')->table('aff_commission_histories')->insert([
                'user_id' => $user_id,
                'from_id' => $banner ? $banner->id : null,
                'from_type' => $banner ? 'banner' : null,
                'commission' => $this->getCommissionData($commission_type),
                'type' => $commission_type,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'information' => json_encode($extra_info),
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
        $this->findOrCreateAffiliateUserWallet($user_id, true, ($banner && $banner->offer_id ? $this->findBannerOfferCommission($banner->offer_id) : $this->getCommissionData($commission_type)));

    }

    public function bannerRegistrationCommission($commission_type, $user_type, $user = null, $host = null): void
    {
        $user = request()->user() ?? $user;
        if ($user->referred) {
            $affiliate_user = $this->getAffiliateUserData($user->referred->referrer);
            $domain = $this->getDomainInfo($host ?? $user->referred->host);
            if ($domain && $domain->status === 'approved' && $affiliate_user && $domain->verified) {
                $banner_id = json_decode($user->referred->info, true, 512)['banner_id'];
                $extra_info = ['name' => $user->name, 'user_type' => $user_type];
                $this->insertCommissionInfo($affiliate_user->id, $commission_type, $this->getBannerData($banner_id), $extra_info);
            }
        }
    }


    public function directRegistrationCommission($commission_type, $user_type, $user = null)
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

    public function storeReferrerData($userData)
    {
        try {
            $commission = null;
            $info = null;
            $referrer = null;
            if (request()->b !== 'null') {
                $banner = $this->getBannerData(base64_decode(request()->b));
                if ($banner){
                    if ($banner->offer_id) {
                        $offer = $this->findBannerOfferCommission($banner->offer_id);
                        $commission = $offer->user_commission;
                    }
                    $info = json_encode([
                        'banner_id' => $banner->id,
                    ]);
                }
            }
            if (request()->u !== 'null') {
                $referrer = request()->u;
            } else {
                if (request()->referrer_code !== 'null') {
                    $referrer = request()->referrer_code;
                }
            }
            if (request()->referrer_code || request()->u !== 'null' || request()->b !== 'null') {
                Models\Referrer::create([
                    'user_id' => $userData->id,
                    'user_type' => get_class($userData),
                    'referrer' => $referrer,
                    'host' => request()->h ?? null,
                    'commission' => $commission,
                    'info' => $info
                ]);
            }
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
    public function giveCommission($userData, $user_type)
    {
        try {
            $type = $userData->referred && $userData->referred->info !== NULL ? 'banner' : 'direct';
            $commission_type = $user_type . '-' . $type . '-registration';
            $userData->referred && $userData->referred->info !== NULL ? $this->bannerRegistrationCommission($commission_type, $user_type, $userData) : $this->directRegistrationCommission($commission_type, $user_type, $userData);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
        }
    }

    public function getDomainInfo($host)
    {
        return DB::connection('affiliate')->table('user_sites')->where('code', $host)->first();
    }

    public function giveClickViewCommissionByCookie($refer_code = null, $encoded_banner = null, $cookie_name = '_ar_click')
    {
        $refer_code = $refer_code ?? request()->u;
        $encoded_banner = $encoded_banner ?? request()->b;
        if (request()->headers->get('referer') && request()->b) {
            $cookie_value = $_COOKIE[$cookie_name] ?? '';
            $this->incrementStatistics($this->getBannerData(base64_decode($encoded_banner)), 'App\Models\Banner', $this->getAffiliateUserData($refer_code), 'click', true);
            if (!$cookie_value) {
                $this->incrementStatistics($this->getBannerData(base64_decode($encoded_banner)), 'App\Models\Banner', $this->getAffiliateUserData($refer_code));
                $this->giveClickCommission($refer_code, $encoded_banner);
                $cookie_value = request()->b;
            } elseif (!in_array(request()->b, explode(',', $cookie_value), true)) {
                $this->incrementStatistics($this->getBannerData(base64_decode($encoded_banner)), 'App\Models\Banner', $this->getAffiliateUserData($refer_code));
                $this->giveClickCommission($refer_code, $encoded_banner);
                $cookie_value .= ',' . request()->b;
            }
            setcookie($cookie_name, $cookie_value, 0, '/');
        }
    }

    public function giveClickCommission($refer_code, $encoded_banner)
    {
        $referrer_site = request()->headers->get('referer');
        $host = base64_encode(parse_url($referrer_site)['host']);

        $banner = $this->getBannerData(base64_decode($encoded_banner));
        $commission_type = $banner->type . '-banner-registration-click';

        $affiliate_user = $this->getAffiliateUserData($refer_code);
        $domain = $this->getDomainInfo($host);
        if ($domain && $domain->status === 'approved' && $affiliate_user && $domain->verified) {
            $this->insertCommissionInfo($affiliate_user->id, $commission_type, $banner);
        }
    }

    public function getStatisticsData($item, $model)
    {
        return DB::connection('affiliate')->table('statistics')->where(['reportable_id' => $item->id, 'reportable_type' => $model])->latest()->first();
    }


    public function incrementStatistics($item, $model, $refer_code, string $type = 'click', bool $total_only = false): void
    {

        $affiliate_user = $this->getAffiliateUserData($refer_code);
        if ($model === 'App\Models\Seller\Product' || $model === 'App\Models\Seller\Service') {
            $this->sourcesProductServiceStatistics($item, $model, $type);
        }
        $existsItem = $this->getStatisticsData($item, $model);
        if ($total_only) {
            DB::connection('affiliate')->table('statistics')->where(['reportable_id' => $item->id, 'reportable_type' => 'App\Models\Banner'])->latest()->limit(1)->update([
                'total_click' => $existsItem->total_click + 1
            ]);
        } else {
            if ($type === 'view') {
                $view = $existsItem ? $existsItem->view + 1 : 1;
                $click = $existsItem->click ?? 0;
            } else {
                $view = $existsItem->view ?? 0;
                $click = $existsItem ? $existsItem->click + 1 : 1;
            }
            $total_view = $existsItem ? $existsItem->total_view + 1 : 1;
            $total_click = $existsItem ? $existsItem->total_click + 1 : 1;

            DB::connection('affiliate')->table('statistics')->insert([
                'user_id' => $affiliate_user->id,
                'ip_address' => request()->ip(),
                'user_agent' => json_encode(\request()->server('HTTP_USER_AGENT')),
                'reportable_id' => $item->id,
                'reportable_type' => $model,
                'view' => $view,
                'total_view' => $total_view,
                'click' => $click,
                'total_click' => $total_click,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function sourcesProductServiceStatistics($item, $model, $type)
    {
        $savedCommission = 0;
        if ($type === 'click') {
            if ($model === 'App\Models\Seller\Product') {
                $savedCommission = $this->getCommissionData('product-click');
            } else {
                $savedCommission = $this->getCommissionData('service-click');
            }
            $item->affiliateObject->increment('total_click', 1);

        } elseif ($type === 'view') {
            if ($model === 'App\Models\Seller\Product') {
                $savedCommission = $this->getCommissionData('product-view');
            } else {
                $savedCommission = $this->getCommissionData('service-view');
            }
            $item->affiliateObject->increment('total_view', 1);
        }

        $item->affiliateObject->update([
            'total_cost' => ($item->affiliateObject->total_click * $savedCommission) + ($item->affiliateObject->total_view * $savedCommission),
        ]);
    }
}
