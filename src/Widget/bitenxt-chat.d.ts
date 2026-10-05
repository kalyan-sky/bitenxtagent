// Type declarations for <bitenxt-chat>. Copy into the Pro frontend (e.g. src/types/bitenxt-chat.d.ts).

export interface BitenxtChatElement extends HTMLElement {
  /** The customer's Magento token (Bearer). Set null on logout. Never rendered or stored by the component. */
  token: string | null;
  /** Called whenever the component needs the token; takes priority over `token` and `token-key`. */
  getToken: (() => string | null | undefined) | null;
  /** Quick-reply chips shown under the greeting. [] hides them. */
  suggestions: string[];
  open(): void;
  close(): void;
  toggle(): void;
  /** Opens the chat and sends a message as if the customer typed it. */
  send(text: string): void;
}

export interface BitenxtChatEventMap {
  'bitenxt-open': CustomEvent<{}>;
  'bitenxt-close': CustomEvent<{}>;
  'bitenxt-message-sent': CustomEvent<{ text: string }>;
  'bitenxt-reply': CustomEvent<{ text: string; status: number }>;
  'bitenxt-feedback': CustomEvent<{ rating: 'up' | 'down' }>;
  /** The token is missing, expired or rejected: Pro should ask the customer to log in again. */
  'bitenxt-auth-required': CustomEvent<{}>;
}

declare global {
  interface HTMLElementTagNameMap {
    'bitenxt-chat': BitenxtChatElement;
  }
}

/* React (JSX) attribute typing. In React 18, set `token`/`getToken` and listen to
   events through a ref; React 19 passes properties and `on…` events directly. */
declare module 'react' {
  namespace JSX {
    interface IntrinsicElements {
      'bitenxt-chat': React.DetailedHTMLProps<React.HTMLAttributes<BitenxtChatElement>, BitenxtChatElement> & {
        'api-url'?: string;
        'token-key'?: string;
        'token-source'?: 'localStorage' | 'sessionStorage' | 'cookie';
        'token-path'?: string;
        heading?: string;
        greeting?: string;
        placeholder?: string;
        'support-phone'?: string;
        position?: 'right' | 'left';
        mode?: 'floating' | 'inline';
        open?: boolean;
        suggestions?: string;
      };
    }
  }
}
