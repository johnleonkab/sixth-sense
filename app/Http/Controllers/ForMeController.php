<?php

namespace App\Http\Controllers;

use App\Avisos\ForMe;
use App\Models\InboxItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ForMeController extends Controller
{
    /**
     * Reading is free: opening an aviso marks it read for this person only.
     */
    public function read(Request $request, int $item): RedirectResponse
    {
        $aviso = $this->owned($request, $item);

        if ($aviso->read_at === null) {
            $aviso->update(['read_at' => now()]);
        }

        return back();
    }

    /**
     * Mark every open aviso as read.
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->inboxItems()
            ->whereIn('source', ForMe::SOURCES)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    private function owned(Request $request, int $item): InboxItem
    {
        return $request->user()->inboxItems()->whereIn('source', ForMe::SOURCES)->findOrFail($item);
    }
}
