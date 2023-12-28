import template from './muwa-search-structure-detail.html.twig';

const {Criteria} = Shopware.Data;
const {Component, Mixin} = Shopware;
const {mapPropertyErrors} = Shopware.Component.getComponentHelper();
const { debounce, createId, object: { cloneDeep } } = Shopware.Utils;

Component.register('muwa-search-structure-detail', {
    template,

    inject: [
        'repositoryFactory',
        'salesChannelService'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    metaInfo() {

        return {
            title: this.$createTitle()
        };
    },

    data() {

        return {

            isLoading: false,
            processSuccess: false,
            salesChannels: null,
            indexStructure: {
                mappings: null,
                translated: {
                    mappings: null
                }
            },
            searchTerm: null,
            mappings: [],
            currencies: [],
            languages: [],
            customFieldSets: [],
            addMappingEnabled: false,
            systemRequiredFields: {
                type: Object,
                required: false,
                default() {
                    return {};
                }
            },
            httpClient: null,
            sortBy: {
                type: String,
                default: 'createdAt',
                required: false,
            },

            sortDirection: {
                type: String,
                default: 'DESC',
                required: false,
            }
        };
    },

    computed: {

        repositoryIndexStructure() {
            return this.repositoryFactory.create('muwa_index_structure');
        },

        ...mapPropertyErrors('indexStructure', ['translated',]),

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        salesChannelCriteria() {
            const criteria = new Criteria(1, 50);
            criteria.addFilter(Criteria.equals('active', true));

            return criteria;
        },

        customFieldSetRepository() {
            return this.repositoryFactory.create('custom_field_set');
        },

        indexStructureCriteria() {
            const criteria = new Criteria();
            criteria.addAssociation('translations');
            return criteria;
        },

        customFieldSetCriteria() {
            const criteria = new Criteria(1, 500);
            criteria.addAssociation('relations');
            criteria.addAssociation('customFields');

            return criteria;
        },

        getMappings() {
            return this.indexStructure.translated.mappings;
        },

        mappingColumns() {
            let columns = [
                {
                    property: 'entry',
                    label: 'muwa-search-structure.mappingList.entityLabel',
                    allowResize: true,
                    width: '300px',
                },
                {
                    property: 'inputDataType',
                    label: 'muwa-search-structure.mappingList.inputDataTypeLabel',
                    allowResize: true,
                    width: '300px',
                },
                {
                    property: 'defaultValue',
                    label: 'muwa-search-structure.mappingList.defaultValue',
                    allowResize: true,
                    width: '300px',
                }
            ];

            return columns;
        },

        mappingsExist() {

            if(this.indexStructure.translated.mappings) {
                return this.indexStructure.translated.mappings.length > 0;
            }
            return false;
        },

        sortingConditionConcatenation() {
            return `${this.sortBy}:${this.sortDirection}`;
        },
    },

    created() {

        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.createdComponent();
    },

    methods: {
        createdComponent() {

            this.getIndexStructure();
            this.getSalesChannels();
            this.loadMappings();
        },


        getIndexStructure() {
            this.repositoryIndexStructure
                .get(this.$route.params.id, Shopware.Context.api, this.indexStructureCriteria)
                .then((entity) => {
                    this.indexStructure = entity;
                });
        },

        getSalesChannels() {

            this.salesChannelRepository.search(this.salesChannelCriteria, Shopware.Context.api).then(res => {
                this.salesChannels = res;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        onClickSave() {

            this.isLoading = true;
            this.repositoryIndexStructure
                .save(this.indexStructure, Shopware.Context.api, this.indexStructureCriteria)
                .then(() => {

                    this.httpClient.post(
                        '/_action/muwa/search/save-mappings',
                        this.indexStructure,
                        {
                            headers: this.getApiHeader()
                        }

                    ).then((response) => {

                        this.isLoading = false;
                        this.getIndexStructure();
                        this.createNotificationSuccess({
                            title: this.$tc('muwa-search-structure.general.saveSuccessAlertTitle'),
                            message: this.$tc('muwa-search-structure.general.saveSuccessAlertMessage')
                        });
                    })
                }).catch((exception) => {
                    this.isLoading = false;

                    this.createNotificationError({
                        title: this.$tc('muwa-search-structure.create.errorTitle'),
                        message: exception
                    });
                });
        },

        dataTypeOptions() {

            this.httpClient.get(
                '/_action/muwa/server/mapping-input-data-types',
                this.getApiHeader()
            ).then((response) => {

                console.log('res', response);

                return [
                    { value: 'name:ASC', name: 'test' },
                    { value: 'name:DESC', name: this.$tc('sw-cms.sorting.labelSortByNameDesc') },
                    { value: 'createdAt:DESC', name: this.$tc('sw-cms.sorting.labelSortByCreatedDsc') },
                    { value: 'createdAt:ASC', name: this.$tc('sw-cms.sorting.labelSortByCreatedAsc') },
                    { value: 'updatedAt:DESC', name: this.$tc('sw-cms.sorting.labelSortByUpdatedDsc') },
                    { value: 'updatedAt:ASC', name: this.$tc('sw-cms.sorting.labelSortByUpdatedAsc') },
                ];
            });
        },

        saveFinish() {
            this.processSuccess = false;
        },

        onChangeLanguage(languageId) {
            Shopware.State.commit('context/setApiLanguageId', languageId);
            this.createdComponent();
        },

        toggleAddMappingActionState(sourceEntity) {
            this.addMappingEnabled = !!sourceEntity;
        },

        onDeleteMapping(id) {

            this.indexStructure.translated.mappings = this.indexStructure.translated.mappings.filter((mapping) => {
                return mapping.id !== id;
            });

            this.loadMappings();
        },

        loadMappings() {

            if(this.indexStructure) {

                if(this.indexStructure.translated.mappings) {

                    this.indexStructure.translated.mappings.forEach((mapping) => {
                        if (!mapping.id) {
                            mapping.id = createId();
                        }
                    });
                }
            }
        },

        onAddMapping() {

            this.indexStructure.translated.mappings.forEach(currentMapping => { currentMapping.position += 1; });
            this.indexStructure.translated.mappings.unshift({
                id: createId(),
                isDefault: false,
                key: '',
                mappedKey: '',
                position: 0
            });

            this.loadMappings();
        },

        isDefaultValueTextFieldDisabled(item) {
            // return this.profile.systemDefault || !item.useDefaultValue;
        },

        getApiHeader() {

            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${ Shopware.Context.api.authToken.access }`,
                'Content-Type': 'application/json'
            }
        }
    }
});