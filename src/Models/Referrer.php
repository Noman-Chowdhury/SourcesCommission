<?php

namespace Sources\AffiliateCommission\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Referrer extends Model
{
    use HasFactory;

    protected $fillable=['user_id','user_type','referrer','host','commission','info'];

    public function getTable()
    {
        return config('commissions.table_names.referrers', parent::getTable());
    }

}
