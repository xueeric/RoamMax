import React, { useState } from 'react';
import { ChevronLeft, ChevronRight, Info, CheckCircle2, AlertCircle } from 'lucide-react';
import { RentalPlan, BookingRequest } from '../types';

interface InteractiveCalendarProps {
  selectedPlan: RentalPlan;
  bookings: BookingRequest[];
  onChangeRange: (start: string | null, end: string | null) => void;
  startDateStr: string | null;
  endDateStr: string | null;
}

// Fixed mock bookings for visual presentation of blocked dates
const MOCK_BLOCKED_RANGES = [
  { start: '2026-05-27', end: '2026-05-30', label: 'Booked' },
  { start: '2026-06-11', end: '2026-06-15', label: 'Enterprise deployment' },
  { start: '2026-06-25', end: '2026-06-28', label: 'In-motion test' }
];

export default function InteractiveCalendar({
  selectedPlan,
  bookings,
  onChangeRange,
  startDateStr,
  endDateStr
}: InteractiveCalendarProps) {
  // We starts viewing from May 2026 (as simulated local time is May 23, 2026)
  const [currentYear, setCurrentYear] = useState(2026);
  const [currentMonth, setCurrentMonth] = useState(4); // 4 = May in JS Date (0-indexed)

  const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  // Increment/Decrement Months
  const handlePrevMonth = () => {
    if (currentMonth === 0) {
      setCurrentMonth(11);
      setCurrentYear(prev => prev - 1);
    } else {
      setCurrentMonth(prev => prev - 1);
    }
  };

  const handleNextMonth = () => {
    if (currentMonth === 11) {
      setCurrentMonth(0);
      setCurrentYear(prev => prev + 1);
    } else {
      setCurrentMonth(prev => prev + 1);
    }
  };

  // Helper date conversions
  const formatDateString = (year: number, month: number, day: number) => {
    const mm = String(month + 1).padStart(2, '0');
    const dd = String(day).padStart(2, '0');
    return `${year}-${mm}-${dd}`;
  };

  // Check if a date is blocked/pre-booked
  const isDateBlocked = (dateStr: string) => {
    const dateObj = new Date(dateStr);
    
    // 1. Check preexisting static mock blocks
    for (const b of MOCK_BLOCKED_RANGES) {
      if (dateStr >= b.start && dateStr <= b.end) {
        return true;
      }
    }

    // 2. Check current booked client listings in parent state that matches selected kit ID
    for (const booking of bookings) {
      if (booking.kitId === selectedPlan.id && dateStr >= booking.startDate && dateStr <= booking.endDate) {
        return true;
      }
    }

    // 3. Prevent booking dates in the past (before simulated May 23, 2026)
    const simulatedToday = '2026-05-23';
    if (dateStr < simulatedToday) {
      return true;
    }

    return false;
  };

  // Handle cell click
  const handleDateClick = (dateStr: string) => {
    if (isDateBlocked(dateStr)) return;

    if (!startDateStr || (startDateStr && endDateStr)) {
      // First click or reset - set start date
      onChangeRange(dateStr, null);
    } else {
      // Second click - set end date
      if (dateStr < startDateStr) {
        // If they click an earlier date, make it the new start date
        onChangeRange(dateStr, null);
      } else {
        // Ensure no blocked dates are inside the selected range
        let hasOverlap = false;
        let scanDate = new Date(startDateStr);
        const targetDate = new Date(dateStr);

        while (scanDate <= targetDate) {
          const checkStr = scanDate.toISOString().split('T')[0];
          if (isDateBlocked(checkStr)) {
            hasOverlap = true;
            break;
          }
          scanDate.setDate(scanDate.getDate() + 1);
        }

        if (hasOverlap) {
          alert('Cannot book range: Selected range overlaps with currently unavailable/booked days.');
          return;
        }

        onChangeRange(startDateStr, dateStr);
      }
    }
  };

  // Generate days array for a specific month
  const getMonthDays = (year: number, month: number) => {
    const totalDays = new Date(year, month + 1, 0).getDate();
    const startDay = new Date(year, month, 1).getDay(); // 0 is Sunday
    
    const days = [];
    // Padding for early days
    for (let i = 0; i < startDay; i++) {
      days.push(null);
    }
    // Days of month
    for (let d = 1; d <= totalDays; d++) {
      days.push(d);
    }
    return days;
  };

  // Check if date is hovered/within selected range
  const isDateInSelectedRange = (dateStr: string) => {
    if (!startDateStr || !endDateStr) return false;
    return dateStr >= startDateStr && dateStr <= endDateStr;
  };

  const isStartOfRange = (dateStr: string) => dateStr === startDateStr;
  const isEndOfRange = (dateStr: string) => dateStr === endDateStr;

  // Let's draw two months side by side for a gorgeous, interactive, high-fidelity experience
  const month1Year = currentYear;
  const month1Val = currentMonth;
  const month2Year = currentMonth === 11 ? currentYear + 1 : currentYear;
  const month2Val = currentMonth === 11 ? 0 : currentMonth + 1;

  const renderMonthGrid = (year: number, month: number) => {
    const days = getMonthDays(year, month);
    const dayLabels = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    return (
      <div className="flex-1">
        <div className="text-center font-display text-sm font-bold text-gray-900 mb-4 flex items-center justify-between px-2">
          <span>{monthNames[month]} {year}</span>
        </div>

        {/* Day labels header */}
        <div className="grid grid-cols-7 gap-1 text-center font-mono text-[10px] font-bold text-gray-400 mb-1.5 uppercase">
          {dayLabels.map(l => (
            <div key={l} className="py-1">{l}</div>
          ))}
        </div>

        {/* Days grid */}
        <div className="grid grid-cols-7 gap-1">
          {days.map((day, idx) => {
            if (day === null) {
              return <div key={`empty-${idx}`} className="aspect-square" />;
            }

            const dateStr = formatDateString(year, month, day);
            const blocked = isDateBlocked(dateStr);
            const inRange = isDateInSelectedRange(dateStr);
            const isStart = isStartOfRange(dateStr);
            const isEnd = isEndOfRange(dateStr);

            let cellClass = "aspect-square flex flex-col items-center justify-center rounded-lg text-xs font-semibold cursor-pointer transition relative ";
            if (blocked) {
              cellClass += "bg-red-50 text-red-300 line-through cursor-not-allowed border border-red-100/50";
            } else if (isStart || isEnd) {
              cellClass += "bg-black text-white shadow-sm ring-1 ring-black z-10 font-black";
            } else if (inRange) {
              cellClass += "bg-gray-100 text-black font-semibold border-y border-gray-200";
            } else {
              cellClass += "bg-white text-gray-800 hover:bg-gray-50 border border-gray-100";
            }

            return (
              <div
                key={dateStr}
                onClick={() => handleDateClick(dateStr)}
                className={cellClass}
                title={blocked ? 'Unavailable / Already Reserved' : `Available - Click to select: ${dateStr}`}
              >
                <span>{day}</span>
                {blocked && (
                  <span className="absolute bottom-1 w-1 h-1 rounded-full bg-red-400" />
                )}
                {isStart && !endDateStr && (
                  <span className="absolute bottom-0.5 text-[7px] text-gray-300 font-mono scale-90 uppercase tracking-tighter">Start</span>
                )}
                {isStart && endDateStr && (
                  <span className="absolute bottom-0.5 text-[6px] text-gray-300 font-mono tracking-tighter">IN</span>
                )}
                {isEnd && (
                  <span className="absolute bottom-0.5 text-[6px] text-gray-300 font-mono tracking-tighter">OUT</span>
                )}
              </div>
            );
          })}
        </div>
      </div>
    );
  };

  return (
    <div className="border border-gray-150 rounded-2xl bg-white p-5 sm:p-6 shadow-sm relative overflow-hidden" id="calendar-date-selector">
      
      {/* Calendar Header with Controls */}
      <div className="flex justify-between items-center pb-4 border-b border-gray-100 mb-6">
        <div>
          <h4 className="font-display text-sm font-bold text-gray-950 flex items-center gap-2">
            <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
            <span>Range Reservation Calendar</span>
          </h4>
          <p className="text-[11px] text-gray-400 mt-0.5">
            Click your start and end dates below. Dates marked in red are already booked by others.
          </p>
        </div>
        
        {/* Nav month buttons */}
        <div className="flex items-center space-x-1">
          <button
            type="button"
            onClick={handlePrevMonth}
            className="p-1.5 rounded-lg border border-gray-200 hover:border-black transition hover:bg-gray-50 text-gray-700 cursor-pointer"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <button
            type="button"
            onClick={handleNextMonth}
            className="p-1.5 rounded-lg border border-gray-200 hover:border-black transition hover:bg-gray-50 text-gray-700 cursor-pointer"
          >
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      </div>

      {/* Legend Block */}
      <div className="flex flex-wrap gap-4 text-[11px] text-gray-500 mb-5 pb-2 font-mono justify-center border-b border-gray-50">
        <div className="flex items-center gap-1.5">
          <span className="w-3" />
          <div className="w-4 h-4 rounded border border-gray-100 bg-white" />
          <span>Available Date</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-4 h-4 rounded-md bg-black border border-black" />
          <span>Your Selected Range</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-4 h-4 rounded bg-red-50 text-red-300 line-through border border-red-100 flex items-center justify-center text-[8px] font-bold">14</div>
          <span>Reserved / Blocked</span>
        </div>
      </div>

      {/* Two Months side by side */}
      <div className="flex flex-col md:flex-row gap-8">
        {renderMonthGrid(month1Year, month1Val)}
        {renderMonthGrid(month2Year, month2Val)}
      </div>

      {/* Feedback summary */}
      <div className="mt-5 pt-4 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between text-xs gap-3">
        {startDateStr ? (
          <div className="flex items-center space-x-2 bg-gray-50 border border-gray-200 px-3 py-2 rounded-xl text-gray-800">
            <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
            <span>
              Selected Term:{' '}
              <strong className="font-mono text-black font-semibold bg-gray-100 rounded px-1.5 py-0.5">{startDateStr}</strong>
              {endDateStr ? (
                <>
                  {' '}to{' '}
                  <strong className="font-mono text-black font-semibold bg-gray-100 rounded px-1.5 py-0.5">{endDateStr}</strong>
                </>
              ) : (
                <span className="text-gray-400 italic"> (choose return date...)</span>
              )}
            </span>
          </div>
        ) : (
          <div className="flex items-center space-x-2 text-gray-400 py-1 italic">
            <Info className="h-4 w-4 text-gray-400 shrink-0" />
            <span>No travel dates selected yet. Please click any available calendar cell to start your quote.</span>
          </div>
        )}

        {(startDateStr || endDateStr) && (
          <button
            type="button"
            onClick={() => onChangeRange(null, null)}
            className="text-[10px] uppercase font-mono font-bold tracking-wider text-gray-500 hover:text-red-600 transition underline cursor-pointer"
          >
            Clear Selected Range
          </button>
        )}
      </div>

    </div>
  );
}
