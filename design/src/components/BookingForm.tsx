import React, { useState } from 'react';
import { User, Mail, Phone, MapPin, ClipboardList, Info, CheckCircle, Flame, Layers, BadgePercent } from 'lucide-react';
import { RENTAL_PLANS } from '../data/rentalData';
import { BookingRequest } from '../types';
import InteractiveCalendar from './InteractiveCalendar';

interface BookingFormProps {
  selectedKitId: string;
  onSelectKitId: (kitId: string) => void;
  onBookingSubmitted: (request: BookingRequest) => void;
  bookings: BookingRequest[]; // Pass active bookings to check calendar collisions
}

export default function BookingForm({ selectedKitId, onSelectKitId, onBookingSubmitted, bookings }: BookingFormProps) {
  // Personal Info State
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  
  // Dates State (Managed primarily through InteractiveCalendar selection now!)
  const [startDate, setStartDate] = useState<string | null>(null);
  const [endDate, setEndDate] = useState<string | null>(null);
  
  // Shipping State
  const [shippingAddress, setShippingAddress] = useState('');
  const [city, setCity] = useState('');
  const [state, setState] = useState('');
  const [zipCode, setZipCode] = useState('');
  const [specialInstructions, setSpecialInstructions] = useState('');

  // Status State
  const [success, setSuccess] = useState(false);
  const [currentRequest, setCurrentRequest] = useState<BookingRequest | null>(null);

  const selectedPlan = RENTAL_PLANS.find(p => p.id === selectedKitId) || RENTAL_PLANS[0];

  // Recalculate duration & price only when dates are validly set
  let diffDays = 0;
  let calculatedWeeks = 0;
  let rawRentalCost = 0;
  let securityDeposit = selectedPlan.kitCost.deposit;
  let shippingFlatFee = 25;
  let salesTax = 0;
  let aggregateTotal = 0;

  if (startDate && endDate) {
    const startObj = new Date(startDate);
    const endObj = new Date(endDate);
    const diffTime = Math.abs(endObj.getTime() - startObj.getTime());
    diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // inclusive checkout day
    calculatedWeeks = Math.ceil(diffDays / 7) || 1;

    // Monthly vs Weekly savers from Starlink DB rates
    const monthsCoef = Math.floor(calculatedWeeks / 4);
    const remainingWeeksCoef = calculatedWeeks % 4;
    rawRentalCost = (monthsCoef * selectedPlan.kitCost.rentalPerMonth) + (remainingWeeksCoef * selectedPlan.kitCost.rentalPerWeek);
    salesTax = Math.round(rawRentalCost * 0.08);
    aggregateTotal = rawRentalCost + securityDeposit + shippingFlatFee + salesTax;
  }

  const handleCalendarRangeChange = (start: string | null, end: string | null) => {
    setStartDate(start);
    setEndDate(end);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!startDate || !endDate) {
      alert('Error: Please click on the calendar cells above to select your trip start and end dates.');
      return;
    }

    const uniqueId = 'ob-' + Math.floor(100000 + Math.random() * 900000);
    const newRequest: BookingRequest = {
      id: uniqueId,
      fullName,
      email,
      phone,
      kitId: selectedKitId,
      startDate,
      endDate,
      shippingAddress,
      city,
      state,
      zipCode,
      specialInstructions,
      totalPrice: aggregateTotal,
      depositAmount: securityDeposit,
      status: 'pending',
      createdAt: new Date().toISOString()
    };

    onBookingSubmitted(newRequest);
    setCurrentRequest(newRequest);
    setSuccess(true);
    
    // Clear dates & inputs
    setStartDate(null);
    setEndDate(null);
    setFullName('');
    setEmail('');
    setPhone('');
    setShippingAddress('');
    setCity('');
    setState('');
    setZipCode('');
    setSpecialInstructions('');
  };

  const handleDismissSuccess = () => {
    setSuccess(false);
    setCurrentRequest(null);
  };

  return (
    <div className="rounded-2xl border border-gray-150 bg-white shadow-sm relative overflow-hidden p-6 sm:p-10" id="rental-form-block">
      
      {success && currentRequest ? (
        <div className="text-center py-6 animate-scaleIn" id="booking-success-message">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-800 border border-emerald-100 mb-4 animate-bounce">
            <CheckCircle className="h-7 w-7" />
          </div>
          <h3 className="font-display text-2xl font-bold text-gray-950">Booking Requested Successfully</h3>
          <p className="text-sm text-gray-500 mt-2 max-w-md mx-auto">
            Your booking reference transaction ID is <span className="font-mono text-black font-semibold bg-gray-100 px-2 py-0.5 rounded text-xs">{currentRequest.id}</span>. This spot is now blocked out on the constellation range checker.
          </p>

          {/* Details list */}
          <div className="my-6 rounded-xl bg-gray-50 p-5 max-w-sm mx-auto text-left border border-gray-200 text-xs font-mono space-y-2 text-gray-600">
            <p className="text-[9px] text-gray-400 uppercase tracking-widest mb-2 font-bold pb-2 border-b border-gray-200">
              Receipt Parameters
            </p>
            <div className="flex justify-between">
              <span className="text-gray-400">Applicant:</span>
              <span className="text-gray-900 font-semibold">{currentRequest.fullName}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-400">Hardware Kit:</span>
              <span className="text-gray-900 font-semibold">{selectedPlan.name}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-400">Duration Range:</span>
              <span className="text-gray-900 font-semibold">{currentRequest.startDate} to {currentRequest.endDate}</span>
            </div>
            <div className="flex justify-between animate-pulse">
              <span className="text-gray-400">Deposit Included:</span>
              <span className="text-gray-900 font-semibold">${currentRequest.depositAmount}</span>
            </div>
            <div className="flex justify-between border-t border-gray-200 pt-2 text-sm text-gray-900">
              <span className="text-gray-500 font-bold">Estimated Invoice Total:</span>
              <span className="text-black font-bold">${currentRequest.totalPrice}</span>
            </div>
          </div>

          <p className="text-xs text-gray-500 max-w-xs mx-auto mb-6 leading-relaxed">
            A confirmation satellite telemetry response simulation is dispatching to <strong>{currentRequest.email}</strong>. Our logistics coordinators usually reply within minutes.
          </p>

          <button
            onClick={handleDismissSuccess}
            className="rounded-xl bg-black px-6 py-3 text-xs font-bold text-white shadow-sm transition hover:bg-gray-800 active:scale-95 text-center inline-block cursor-pointer"
            id="dismiss-booking-success"
          >
            Submit Another Request
          </button>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-8">
          
          {/* Header */}
          <div className="border-b border-gray-100 pb-5">
            <div className="flex items-center space-x-2">
              <span className="font-mono text-[10px] font-bold uppercase tracking-widest text-emerald-600 tracking-wider">01. Orbital Hardware &amp; Schedule</span>
            </div>
            <h3 className="font-display text-xl font-extrabold text-gray-900 mt-2">Instant Booking Portal</h3>
            <p className="text-xs text-gray-500 mt-1">
              Select an orbital hardware kit, choose available dates on the calendar grid, and view live database price quotations dynamically below.
            </p>
          </div>

          {/* STEP A: Select Kit */}
          <div className="space-y-4">
            <label className="block font-mono text-[10px] uppercase tracking-wider text-gray-400 font-bold">
              Step 1: Choose Starlink Terminal Model
            </label>
            
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              {RENTAL_PLANS.map((plan) => {
                const isActive = selectedKitId === plan.id;
                return (
                  <button
                    key={plan.id}
                    type="button"
                    onClick={() => {
                      onSelectKitId(plan.id);
                      // Reset range when model shifts is safer
                      setStartDate(null);
                      setEndDate(null);
                    }}
                    className={`flex flex-col rounded-xl border p-4 text-left transition relative cursor-pointer ${
                      isActive 
                        ? 'border-black bg-gray-50/50 shadow-xs ring-1 ring-black' 
                        : 'border-gray-200 bg-white hover:border-gray-400'
                    }`}
                  >
                    {plan.badge && (
                      <span className="absolute top-2.5 right-2.5 font-mono text-[8px] bg-black text-white text-[7px] font-bold uppercase px-1.5 py-0.5 rounded tracking-wide">
                        {plan.badge}
                      </span>
                    )}
                    <span className="font-display text-xs font-bold text-gray-905">{plan.name}</span>
                    <span className="text-[10px] text-gray-500 mt-1 inline-block line-clamp-2 leading-relaxed">
                      {plan.tagline}
                    </span>
                    <div className="mt-4 pt-3 border-t border-gray-100 flex items-baseline justify-between w-full">
                      <span className="text-xs font-extrabold text-black font-mono">
                        ${plan.kitCost.rentalPerWeek}<span className="text-[10px] font-normal text-gray-400">/wk</span>
                      </span>
                      <span className="text-[9px] font-mono text-gray-400">
                        ${plan.kitCost.deposit} deposit
                      </span>
                    </div>
                  </button>
                );
              })}
            </div>
          </div>

          {/* STEP B: Select Dates Calendar */}
          <div className="space-y-3">
            <label className="block font-mono text-[10px] uppercase tracking-wider text-gray-400 font-bold">
              Step 2: Choose Available Setup Term
            </label>
            
            <InteractiveCalendar
              selectedPlan={selectedPlan}
              bookings={bookings}
              onChangeRange={handleCalendarRangeChange}
              startDateStr={startDate}
              endDateStr={endDate}
            />
          </div>

          {/* STEP C: Enter Logistics & Delivery Details */}
          <div className="pt-4 border-t border-gray-100 space-y-5">
            <label className="block font-mono text-[10px] uppercase tracking-wider text-gray-400 font-bold">
              Step 3: Shipping &amp; Recipient Information
            </label>

            <div className="grid gap-4 md:grid-cols-3">
              {/* Full Name */}
              <div>
                <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                  Recipient Full Name
                </label>
                <div className="relative flex items-center">
                  <User className="absolute left-3.5 h-4 w-4 text-gray-400 pointer-events-none" />
                  <input
                    type="text"
                    required
                    value={fullName}
                    onChange={(e) => setFullName(e.target.value)}
                    placeholder="E.g. Sarah Jenkins"
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-4 text-xs text-gray-950 outline-none focus:border-black"
                  />
                </div>
              </div>

              {/* Email Address */}
              <div>
                <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                  Email Address
                </label>
                <div className="relative flex items-center">
                  <Mail className="absolute left-3.5 h-4 w-4 text-gray-400 pointer-events-none" />
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="sarah@example.com"
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-4 text-xs text-gray-950 outline-none focus:border-black"
                  />
                </div>
              </div>

              {/* Phone Number */}
              <div>
                <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                  Contact Phone
                </label>
                <div className="relative flex items-center">
                  <Phone className="absolute left-3.5 h-4 w-4 text-gray-400 pointer-events-none" />
                  <input
                    type="tel"
                    required
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    placeholder="(555) 012-3456"
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-4 text-xs text-gray-950 outline-none focus:border-black"
                  />
                </div>
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-4">
              {/* Street Address */}
              <div className="md:col-span-2">
                <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                  Shipping Delivery Address
                </label>
                <div className="relative flex items-center">
                  <MapPin className="absolute left-3.5 h-4 w-4 text-gray-400 pointer-events-none" />
                  <input
                    type="text"
                    required
                    value={shippingAddress}
                    onChange={(e) => setShippingAddress(e.target.value)}
                    placeholder="123 Wilderness Trail, Cabin Unit 4"
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-4 text-xs text-gray-950 outline-none focus:border-black"
                  />
                </div>
              </div>

              {/* City */}
              <div>
                <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                  City
                </label>
                <input
                  type="text"
                  required
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                  placeholder="Moab"
                  className="w-full rounded-xl border border-gray-200 bg-gray-50 p-2.5 text-xs text-gray-950 outline-none focus:border-black"
                />
              </div>

              {/* State & Zip Code */}
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                    State
                  </label>
                  <input
                    type="text"
                    required
                    value={state}
                    onChange={(e) => setState(e.target.value)}
                    placeholder="UT"
                    maxLength={2}
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 p-2.5 text-xs text-center text-gray-950 outline-none focus:border-black uppercase font-mono"
                  />
                </div>
                <div>
                  <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                    Zip
                  </label>
                  <input
                    type="text"
                    required
                    value={zipCode}
                    onChange={(e) => setZipCode(e.target.value)}
                    placeholder="84532"
                    maxLength={5}
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 p-2.5 text-xs text-center text-gray-950 outline-none focus:border-black font-mono"
                  />
                </div>
              </div>
            </div>

            {/* Special Instructions / Coordinates */}
            <div>
              <label className="block text-[10px] font-semibold text-gray-650 mb-1">
                Deployment Coordinates or Special Courier Instructions (Optional)
              </label>
              <div className="relative flex items-start">
                <ClipboardList className="absolute left-3.5 h-4 w-4 text-gray-400 mt-3 pointer-events-none" />
                <textarea
                  value={specialInstructions}
                  onChange={(e) => setSpecialInstructions(e.target.value)}
                  rows={2}
                  placeholder="E.g. Shipped to trailhead post-office box, remote base camp coordinate access, or private marina slip..."
                  className="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-4 text-xs text-gray-950 placeholder-gray-400 outline-none focus:border-black min-h-[60px]"
                />
              </div>
            </div>
          </div>

          {/* STEP D: Calculated dynamic receipt or waiting cue */}
          <div className="pt-4 border-t border-gray-150">
            {startDate && endDate ? (
              <div className="rounded-xl border border-amber-100 bg-amber-50/55 p-5 shadow-sm space-y-4 animate-scaleIn">
                <div className="flex justify-between items-center pb-2.5 border-b border-amber-200/50">
                  <div className="flex items-center space-x-2 text-amber-900 font-bold text-xs">
                    <Flame className="h-4 w-4 text-amber-600 fill-current" />
                    <span>Real-time Rate Calculation</span>
                  </div>
                  <span className="font-mono text-[10px] bg-black text-white px-2.5 py-0.5 rounded-full font-bold">
                    {calculatedWeeks} week{calculatedWeeks > 1 ? 's' : ''} ({diffDays} days)
                  </span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-y-1.5 gap-x-8 text-xs text-gray-700 font-mono">
                  <div className="flex justify-between">
                    <span className="text-gray-400">Model Rent:</span>
                    <span>{selectedPlan.name}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-400">Rent Cost:</span>
                    <span className="text-black font-bold">${rawRentalCost}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-400">Security Deposit:</span>
                    <span>${securityDeposit}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-400">FedEx Transit (IP67 box):</span>
                    <span>${shippingFlatFee}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-400">Estimated Surtax (8%):</span>
                    <span>${salesTax}</span>
                  </div>
                  <div className="flex justify-between border-t border-amber-200/40 pt-1.5 font-sans sm:col-span-2 text-sm text-black">
                    <span className="font-bold flex items-center gap-1">
                      <BadgePercent className="h-4 w-4 text-emerald-600" />
                      <span>Net Refundable security deposit returned after check-in:</span>
                    </span>
                    <strong className="font-mono text-emerald-700 font-bold">${securityDeposit}</strong>
                  </div>
                </div>

                <div className="pt-3 border-t border-amber-200 flex justify-between items-baseline">
                  <span className="text-xs font-bold text-gray-900 uppercase tracking-wide">Aggregate Quote Total Due (Included Deposit):</span>
                  <span className="text-xl font-extrabold text-black font-mono">${aggregateTotal}</span>
                </div>
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/50 p-6 text-center text-xs text-gray-400">
                <Layers className="h-5 w-5 mx-auto text-gray-300 mb-2" />
                Please select your reservation travel dates on the calendar above to render exact database calculations.
              </div>
            )}
          </div>

          {/* Form Actions */}
          <div className="pt-2 flex flex-col sm:flex-row justify-between items-center gap-4">
            <span className="text-[11px] text-gray-450 font-sans text-center sm:text-left flex items-center gap-1.5 leading-snug">
              <Info className="h-4 w-4 text-black shrink-0" />
              Pre-paid return FedEx slip included. Place dish back inside the IP67 hard-case when term concludes.
            </span>
            <button
              type="submit"
              disabled={!startDate || !endDate}
              className={`w-full sm:w-auto rounded-xl px-10 py-4 text-xs font-bold shadow-sm tracking-wide transition active:scale-95 text-center cursor-pointer ${
                startDate && endDate 
                  ? 'bg-black text-white hover:bg-gray-800' 
                  : 'bg-gray-200 text-gray-400 cursor-not-allowed'
              }`}
              id="form-submit-button"
            >
              Submit Starlink Request
            </button>
          </div>

        </form>
      )}
    </div>
  );
}
