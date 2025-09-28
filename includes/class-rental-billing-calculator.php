<?php
declare(strict_types=1);

/**
 * Rental Billing Calculator
 * 
 * Calculates rental billing over date ranges with multiple conventions:
 * - Full calendar months (strict)
 * - Any occupied months (lenient) 
 * - Pro-rata breakdown per month
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate billing months for a rental period
 * 
 * @param string $start Y-m-d format
 * @param string $end   Y-m-d format  
 * @param array  $options {
 *   @var string $timezone        Default 'UTC'
 *   @var float|null $monthly_price  Optional monthly price for prorating
 *   @var string $round_mode      'half_up'|'half_down'|'bankers' (default 'half_up')
 *   @var int $scale              Decimal places (default 2)
 * }
 * @return array {
 *   full_months: int,
 *   occupied_months: int,
 *   months: array<array{
 *     year:int, month:int, label:string,
 *     days_in_month:int,
 *     occupied_days:int,
 *     occupancy_fraction:float,
 *     amount:float|null
 *   }>,
 *   totals: array{
 *     prorated_subtotal: float|null
 *   }
 * }
 * @throws InvalidArgumentException
 */
function calculate_billing_months(string $start, string $end, array $options = []): array {
    // Parse options with defaults
    $timezone = $options['timezone'] ?? 'UTC';
    $monthly_price = $options['monthly_price'] ?? null;
    $round_mode = $options['round_mode'] ?? 'half_up';
    $scale = $options['scale'] ?? 2;
    
    // Validate and create date objects
    $tz = new DateTimeZone($timezone);
    
    try {
        $start_date = new DateTimeImmutable($start, $tz);
        $end_date = new DateTimeImmutable($end, $tz);
    } catch (Exception $e) {
        throw new InvalidArgumentException("Invalid date format: " . $e->getMessage());
    }
    
    // Validate date range
    if ($end_date < $start_date) {
        throw new InvalidArgumentException("End date must be >= start date");
    }
    
    // Initialize counters
    $full_months = 0;
    $occupied_months = 0;
    $months = [];
    $prorated_subtotal = null;
    
    // Generate month windows from start to end
    $current_month = getFirstOfMonth($start_date);
    $end_month = getFirstOfMonth($end_date);
    
    while ($current_month <= $end_month) {
        $month_data = calculateMonthData(
            $current_month, 
            $start_date, 
            $end_date, 
            $monthly_price, 
            $round_mode, 
            $scale
        );
        
        $months[] = $month_data;
        
        // Count full months (strict): every day of calendar month is inside range
        if ($month_data['occupied_days'] > 0 && 
            $month_data['occupied_days'] === $month_data['days_in_month']) {
            $full_months++;
        }
        
        // Count occupied months (lenient): at least one day occupied
        if ($month_data['occupied_days'] > 0) {
            $occupied_months++;
        }
        
        // Add to prorated total
        if ($monthly_price !== null && $month_data['amount'] !== null) {
            $prorated_subtotal = ($prorated_subtotal ?? 0.0) + $month_data['amount'];
        }
        
        // Move to next month
        $current_month = $current_month->add(new DateInterval('P1M'));
    }
    
    // Round final subtotal
    if ($prorated_subtotal !== null) {
        $prorated_subtotal = roundAmount($prorated_subtotal, $round_mode, $scale);
    }
    
    return [
        'full_months' => $full_months,
        'occupied_months' => $occupied_months,
        'months' => $months,
        'totals' => [
            'prorated_subtotal' => $prorated_subtotal
        ]
    ];
}

/**
 * Calculate data for a single month
 */
function calculateMonthData(
    DateTimeImmutable $month_start,
    DateTimeImmutable $period_start,
    DateTimeImmutable $period_end,
    ?float $monthly_price,
    string $round_mode,
    int $scale
): array {
    $month_end = getLastOfMonth($month_start);
    $days_in_month = (int)$month_start->format('t');
    
    // Calculate segment overlap
    $segment_start = $month_start > $period_start ? $month_start : $period_start;
    $segment_end = $month_end < $period_end ? $month_end : $period_end;
    
    // Calculate occupied days (inclusive)
    $occupied_days = 0;
    if ($segment_start <= $segment_end) {
        $occupied_days = $segment_end->diff($segment_start)->days + 1;
    }
    
    // Calculate occupancy fraction
    $occupancy_fraction = $days_in_month > 0 ? $occupied_days / $days_in_month : 0.0;
    
    // Calculate prorated amount
    $amount = null;
    if ($monthly_price !== null && $occupied_days > 0) {
        $amount = roundAmount(
            $monthly_price * $occupancy_fraction, 
            $round_mode, 
            $scale
        );
    }
    
    return [
        'year' => (int)$month_start->format('Y'),
        'month' => (int)$month_start->format('n'),
        'label' => $month_start->format('F Y'),
        'days_in_month' => $days_in_month,
        'occupied_days' => $occupied_days,
        'occupancy_fraction' => $occupancy_fraction,
        'amount' => $amount
    ];
}

/**
 * Get first day of month at 00:00:00
 */
function getFirstOfMonth(DateTimeImmutable $date): DateTimeImmutable {
    return $date->setDate(
        (int)$date->format('Y'),
        (int)$date->format('n'),
        1
    )->setTime(0, 0, 0);
}

/**
 * Get last day of month at 23:59:59
 */
function getLastOfMonth(DateTimeImmutable $date): DateTimeImmutable {
    return $date->setDate(
        (int)$date->format('Y'),
        (int)$date->format('n'),
        (int)$date->format('t')
    )->setTime(23, 59, 59);
}

/**
 * Round amount using specified mode
 */
function roundAmount(float $amount, string $round_mode, int $scale): float {
    switch ($round_mode) {
        case 'half_up':
            return round($amount, $scale, PHP_ROUND_HALF_UP);
            
        case 'half_down':
            return round($amount, $scale, PHP_ROUND_HALF_DOWN);
            
        case 'bankers':
            return round($amount, $scale, PHP_ROUND_HALF_EVEN);
            
        default:
            throw new InvalidArgumentException("Invalid round_mode: {$round_mode}");
    }
}