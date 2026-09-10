export const shouldShowTicketScrollButton = (
    ticketSectionTop: number,
    viewportHeight: number,
    revealDistance = 96,
): boolean => ticketSectionTop > viewportHeight + revealDistance;
