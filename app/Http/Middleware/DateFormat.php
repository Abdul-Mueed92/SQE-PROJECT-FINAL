<?php

namespace App\Http\Middleware;

use App\Events\Common\DatesFormating;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Date;
use Illuminate\Validation\ValidationException;

class DateFormat
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (in_array($request->method(), ['POST', 'PATCH', 'PUT'])) {
            $columns = new \stdClass();
            $columns->fields = [
                'paid_at',
                'due_at',
                'issued_at',
                'started_at',
                'ended_at',
                'expire_at',
                'recurring_started_at',
                'recurring_limit_date',
            ];

            event(new DatesFormating($columns, $request));

            $fields = $columns->fields;

            foreach ($fields as $field) {
                $date = $request->get($field);

                if (empty($date)) {
                    continue;
                }

                try {
                    $parsed = Date::parse($date);
                } catch (InvalidFormatException | \TypeError) {
                    throw ValidationException::withMessages([
                        $field => trans('validation.date', ['attribute' => str_replace('_', ' ', $field)]),
                    ]);
                }

                if ($parsed->format('H:i:s') == '00:00:00') {
                    $new_date = $parsed->format('Y-m-d') . ' ' . Date::now()->format('H:i:s');
                } else {
                    $new_date = $parsed->toDateTimeString();
                }

                $request->request->set($field, $new_date);
            }
        }

        return $next($request);
    }
}
