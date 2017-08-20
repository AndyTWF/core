<?php

namespace App\Models\NetworkData;

use App\Models\Airport;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\NetworkData\Pilot
 *
 * @property int $id
 * @property int $account_id
 * @property string $callsign
 * @property int $qualification_id
 * @property string $flight_type
 * @property string $departure_airport
 * @property string $arrival_airport
 * @property string $alternative_airport
 * @property string $aircraft
 * @property int $cruise_altitude
 * @property int $cruise_tas
 * @property string $route
 * @property string $remarks
 * @property float|null $current_latitude
 * @property float|null $current_longitude
 * @property int|null $current_altitude
 * @property int|null $current_groundspeed
 * @property \Carbon\Carbon|null $departed_at
 * @property \Carbon\Carbon|null $arrived_at
 * @property \Carbon\Carbon|null $connected_at
 * @property \Carbon\Carbon|null $disconnected_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \App\Models\Mship\Account $account
 * @property-read \App\Models\Mship\Qualification $qualification
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot offline()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot online()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereAircraft($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereAlternativeAirport($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereArrivalAirport($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereArrivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCallsign($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereConnectedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCruiseAltitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCruiseTas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCurrentAltitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCurrentGroundspeed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCurrentLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereCurrentLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereDepartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereDepartureAirport($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereDisconnectedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereFlightType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereQualificationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereRoute($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\NetworkData\Pilot withinDivision()
 * @mixin \Eloquent
 */
class Pilot extends Model
{
    protected $table = 'networkdata_pilots';
    protected $primaryKey = 'id';
    public $dates = ['departed_at', 'arrived_at', 'connected_at', 'disconnected_at', 'created_at', 'updated_at'];

    protected $fillable = [
        'account_id',
        'callsign',
        'flight_type',
        'departure_airport',
        'arrival_airport',
        'connected_at',
        'disconnected_at',
        'qualification_id',
        'alternative_airport',
        'aircraft',
        'cruise_altitude',
        'cruise_tas',
        'route',
        'remarks',
    ];

    public static function scopeOnline($query)
    {
        return $query->whereNull('disconnected_at');
    }

    public static function scopeOffline($query)
    {
        return $query->whereNotNull('disconnected_at');
    }

    public static function scopeWithinDivision($query)
    {
        return $query->where(function ($subQuery) {
            return $subQuery->where('departure_airport', 'LIKE', 'EG%')
                ->orWhere('arrival_airport', 'LIKE', 'EG%');
        });
    }

    public function account()
    {
        return $this->belongsTo(\App\Models\Mship\Account::class, 'account_id', 'id');
    }

    public function qualification()
    {
        return $this->belongsTo(\App\Models\Mship\Qualification::class);
    }

    public function isOnline()
    {
        return $this->attributes['disconnected_at'] === null;
    }

    public function isAtAirport(Airport $airport = null)
    {
        if (is_null($airport)) {
            return false;
        }

        $location = $airport->containsCoordinates($this->current_latitude, $this->current_longitude);
        $altitude = $this->current_altitude < $airport->altitude + 500;

        return $location && $altitude;
    }

    public function setDisconnectedAtAttribute($timestamp)
    {
        $this->attributes['disconnected_at'] = $timestamp;
        $this->calculateTimeOnline();
    }

    /**
     * Calculate the total number of minutes the user spent online
     * When called this will calculate the total difference in
     * minutes and persist/save the value to the database.
     */
    public function calculateTimeOnline()
    {
        if (!is_null($this->disconnected_at)) {
            $this->minutes_online = $this->connected_at->diffInMinutes($this->disconnected_at);
        }
    }
}
