<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MessageResource;
use App\Filament\Resources\PhotoResource;
use App\Models\Message;
use App\Models\Photo;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PhotoDashboardWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->isPhotoManager() ?? false;
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        $photoCount = $user->is_admin
            ? Photo::count()
            : $user->photos()->count();

        $deviceIds = $user->is_admin
            ? \App\Models\Device::pluck('id')
            : $user->photoDevices()->pluck('id');

        $pendingMessages = Message::whereIn('device_id', $deviceIds)
            ->where('is_read', false)
            ->where('scheduled_at', '>=', Carbon::now())
            ->count();

        $upcomingToday = Message::whereIn('device_id', $deviceIds)
            ->where('is_read', false)
            ->whereBetween('scheduled_at', [Carbon::now(), Carbon::now()->endOfDay()])
            ->count();

        return [
            Stat::make('Photos Uploaded', $photoCount)
                ->description('Tap to upload a new photo')
                ->descriptionIcon('heroicon-m-plus-circle')
                ->color('success')
                ->url(PhotoResource::getUrl('create')),

            Stat::make('Scheduled Messages', $pendingMessages)
                ->description('Tap to schedule a message')
                ->descriptionIcon('heroicon-m-plus-circle')
                ->color($pendingMessages > 0 ? 'info' : 'gray')
                ->url(MessageResource::getUrl('create')),

            Stat::make('Messages Today', $upcomingToday)
                ->description('Showing on device today')
                ->descriptionIcon('heroicon-m-device-phone-mobile')
                ->color($upcomingToday > 0 ? 'warning' : 'gray'),
        ];
    }
}
