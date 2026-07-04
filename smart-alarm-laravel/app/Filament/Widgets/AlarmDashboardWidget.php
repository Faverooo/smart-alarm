<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AlarmResource;
use App\Models\Alarm;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AlarmDashboardWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->isAlarmManager() ?? false;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $deviceIds = $user->is_admin
            ? \App\Models\Device::pluck('id')
            : $user->alarmDevices()->pluck('id');

        $activeCount = Alarm::whereIn('device_id', $deviceIds)
            ->where('is_active', true)
            ->count();

        // Next upcoming one-time alarm
        $nextOneTime = Alarm::whereIn('device_id', $deviceIds)
            ->where('is_active', true)
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '>=', Carbon::today())
            ->orderBy('scheduled_date')
            ->orderBy('time')
            ->first();

        // Next recurring alarm by time today
        $now = Carbon::now(config('app.timezone'));
        $todayName = $now->format('l'); // e.g. "Thursday"
        $nextRecurring = Alarm::whereIn('device_id', $deviceIds)
            ->where('is_active', true)
            ->whereNull('scheduled_date')
            ->whereJsonContains('days_of_week', $todayName)
            // Only alarms still ahead of us today — otherwise an already-fired
            // morning alarm would show as "next" all day long.
            ->where('time', '>=', $now->format('H:i:s'))
            ->orderBy('time')
            ->first();

        $stats = [
            Stat::make('Active Alarms', $activeCount)
                ->description('Tap to create a new alarm')
                ->descriptionIcon('heroicon-m-plus-circle')
                ->color($activeCount > 0 ? 'success' : 'gray')
                ->url(AlarmResource::getUrl('create')),
        ];

        if ($nextOneTime) {
            $stats[] = Stat::make(
                'Next One-time',
                Carbon::parse($nextOneTime->scheduled_date)->format('d/m/Y') . ' ' . substr($nextOneTime->time, 0, 5)
            )
                ->description('One-time alarm')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning');
        }

        if ($nextRecurring) {
            $stats[] = Stat::make(
                'Today (' . substr($todayName, 0, 3) . ')',
                substr($nextRecurring->time, 0, 5)
            )
                ->description('Next recurring alarm today')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info');
        }

        return $stats;
    }
}
