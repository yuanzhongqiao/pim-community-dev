import {Button} from "akeneo-design-system";
import {Translate, useTranslate} from '../../../shared/translate';
import React, {FC} from "react";
import {useRouter} from '../../../shared/router/use-router';
import {useSecurity} from '../../../shared/security';
import {ConnectedApp} from "@src/model/Apps/connected-app";
import returnIconUrl from '../../../common/assets/icons/return.svg';

type Props = {
    connectedApp: ConnectedApp;
};

export const OpenAppButton: FC<Props> = ({connectedApp}) => {
    const security = useSecurity();
    const generateUrl = useRouter();
    const openConnectedAppUrl = `#${generateUrl('akeneo_connectivity_connection_connect_connected_apps_open', {
        connectionCode: connectedApp.connection_code,
    })}`;

    const canOpenApp =
        (!connectedApp.is_test_app && security.isGranted('akeneo_connectivity_connection_open_apps')) ||
        (connectedApp.is_test_app && security.isGranted('akeneo_connectivity_connection_manage_test_apps'));

    return (
        <Button
            level={connectedApp.is_pending || connectedApp.has_outdated_scopes ? 'warning' : 'secondary'}
            href={openConnectedAppUrl}
            disabled={!canOpenApp}
            target='_blank'
        >
            <Translate id='akeneo_connectivity.connection.connect.connected_apps.edit.header.open_app'/>
            <img src={returnIconUrl} alt={'Go to app icon'} height={'13px'}/>
        </Button>
    );
}
