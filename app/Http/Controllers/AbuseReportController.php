<?php

namespace App\Http\Controllers;

use App\Mail\AbuseReportFiled;
use App\Mail\AbuseReportReceived;
use App\Models\AbuseReport;
use App\Support\MailBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * The notice-and-action mechanism the DSA asks of every hosting service (art. 16): anyone can
 * report a form, the four things a valid notice needs are required, and the reporter gets a
 * receipt by email. The decision is sent later by hand, with the reasons art. 17 asks for.
 */
class AbuseReportController extends Controller
{
    public function create()
    {
        return view('report');
    }

    public function store(Request $request)
    {
        // A bot that fills the hidden field is told it worked and nothing is sent.
        if (filled($request->input('_gotcha'))) {
            return redirect()->route('report')->with('reported', true);
        }

        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:20', 'max:5000'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'good_faith' => ['accepted'],
        ], [
            'url.required' => 'Give the web address of the page with the form, or the form\'s FormHaven address.',
            'reason.min' => 'Explain in a sentence or two why the form is illegal or abusive.',
            'good_faith.accepted' => 'Confirm that the report is accurate and complete to the best of your knowledge.',
        ]);

        $report = AbuseReport::create($data);

        // Both emails come out of a small daily budget (config mail.budget.abuse_reports_per_day),
        // because a stranger chooses the receipt's address. Our copy goes first; the report is
        // stored either way and listed on the admin page.
        if (MailBudget::tryTake(MailBudget::ABUSE)) {
            Mail::to(config('legal.email'))->send(new AbuseReportFiled($report));
        }

        $receipt = MailBudget::tryTake(MailBudget::ABUSE);
        if ($receipt) {
            Mail::to($report->email)->send(new AbuseReportReceived($report));
        }

        return redirect()->route('report')->with('reported', $receipt ? true : $report->id);
    }
}
