import React from 'react';
import { FileText, Calendar, Layers, MapPin, XCircle, BadgeHelp } from 'lucide-react';
import { BookingRequest } from '../types';
import { RENTAL_PLANS } from '../data/rentalData';

interface BookingListProps {
  bookings: BookingRequest[];
  onCancelBooking: (bookingId: string) => void;
  onScrollToForm: () => void;
}

export default function BookingList({ bookings, onCancelBooking, onScrollToForm }: BookingListProps) {
  
  const getKitName = (kitId: string) => {
    const plan = RENTAL_PLANS.find(p => p.id === kitId);
    return plan ? plan.name : 'Unknown Starlink Kit';
  };

  const getStatusBadgeClass = (status: BookingRequest['status']) => {
    switch (status) {
      case 'pending': return 'bg-amber-50 text-amber-850 border border-amber-100';
      case 'confirmed': return 'bg-sky-50 text-sky-850 border border-sky-100';
      case 'dispatched': return 'bg-blue-50 text-blue-850 border border-blue-100';
      case 'active': return 'bg-emerald-50 text-emerald-850 border border-emerald-100';
      case 'completed': return 'bg-gray-100 text-gray-600 border border-gray-200';
    }
  };

  return (
    <div className="rounded-2xl border border-gray-150 bg-white p-6 shadow-sm relative overflow-hidden" id="booking-list-container">
      <div className="flex items-center justify-between border-b border-gray-100 pb-4 mb-5">
        <div className="flex items-center space-x-2.5">
          <FileText className="h-5 w-5 text-black" />
          <h3 className="font-display text-lg font-bold text-gray-900">Active Bookings Dashboard</h3>
        </div>
        <span className="font-mono text-xs font-bold text-gray-650 bg-gray-50 border border-gray-200 px-2.5 py-1 rounded-md">
          {bookings.length} Request{bookings.length !== 1 ? 's' : ''}
        </span>
      </div>

      {bookings.length === 0 ? (
        <div className="text-center py-10" id="booking-list-empty">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-50 text-gray-400 border border-gray-100 mb-3.5">
            <BadgeHelp className="h-6 w-6" />
          </div>
          <p className="text-sm font-semibold text-gray-800">No Reservations Yet</p>
          <p className="text-xs text-gray-400 mt-1 max-w-xs mx-auto leading-relaxed">
            Select a Starlink setup option above, type in your delivery zip code, and submit our single reservation form to populate your workspace.
          </p>
          <button
            onClick={onScrollToForm}
            className="mt-4 inline-flex items-center rounded-lg bg-black text-white px-4 py-2 text-xs font-semibold hover:bg-gray-800 transition cursor-pointer"
          >
            Go To Booking Form
          </button>
        </div>
      ) : (
        <div className="space-y-4" id="booking-list">
          {bookings.map((booking) => (
            <div
              key={booking.id}
              className="rounded-xl border border-gray-150 bg-gray-50 p-4 hover:border-gray-250 transition"
              id={`booking-item-${booking.id}`}
            >
              {/* Header metadata row */}
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200/60 pb-3 mb-3">
                <div className="flex items-center space-x-1.5">
                  <span className="font-mono text-xs font-bold text-gray-900">
                    ID: {booking.id}
                  </span>
                  <span className="text-gray-305 text-xs">•</span>
                  <span className="text-[11px] text-gray-400 font-mono">
                    Created {new Date(booking.createdAt).toLocaleDateString()}
                  </span>
                </div>
                <div className="flex items-center space-x-1">
                  <span className={`rounded-xl px-2.5 py-0.5 text-[9px] font-bold font-mono uppercase tracking-wide inline-block ${getStatusBadgeClass(booking.status)}`}>
                    {booking.status}
                  </span>
                </div>
              </div>

              {/* Grid content details */}
              <div className="grid gap-3 sm:grid-cols-2 text-xs text-gray-600">
                
                {/* Product spec block */}
                <div className="space-y-1.5">
                  <div className="flex items-center space-x-2 text-gray-800 font-medium">
                    <Layers className="h-3.5 w-3.5 text-black" />
                    <span>{getKitName(booking.kitId)}</span>
                  </div>
                  
                  <div className="flex items-center space-x-2 pl-5 text-gray-400">
                    <Calendar className="h-3.5 w-3.5" />
                    <span>{booking.startDate} to {booking.endDate}</span>
                  </div>
                </div>

                {/* Delivery block */}
                <div className="space-y-1.5 pl-0 sm:pl-4">
                  <div className="flex items-start space-x-2 text-gray-500">
                    <MapPin className="h-3.5 w-3.5 text-black shrink-0 mt-0.5" />
                    <span className="leading-relaxed">
                      {booking.shippingAddress}, {booking.city}, {booking.state} {booking.zipCode}
                    </span>
                  </div>
                </div>

              </div>

              {/* Actions & Price */}
              <div className="mt-4 pt-3 border-t border-gray-200/60 flex justify-between items-center bg-white px-3 rounded-lg py-2 border">
                <div className="text-xs">
                  <span className="text-gray-400 font-mono text-[10px]">Estimated Bill:</span>{' '}
                  <span className="font-mono font-bold text-gray-900">${booking.totalPrice}</span>
                  <span className="text-[10px] text-gray-400 ml-1.5">(${booking.depositAmount} security returned)</span>
                </div>

                <div className="flex items-center space-x-2">
                  {booking.status === 'pending' ? (
                    <button
                      onClick={() => onCancelBooking(booking.id)}
                      className="inline-flex items-center space-x-1 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 px-2.5 py-1 text-[11px] font-semibold transition cursor-pointer border border-transparent hover:border-red-100"
                      id={`cancel-btn-${booking.id}`}
                    >
                      <XCircle className="h-3.5 w-3.5" />
                      <span>Retract</span>
                    </button>
                  ) : (
                    <span className="text-[11px] text-gray-400 font-mono italic">Locked for logistics</span>
                  )}
                </div>
              </div>

            </div>
          ))}
        </div>
      )}
    </div>
  );
}
